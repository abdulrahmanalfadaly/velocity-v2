<?php

namespace App\Actions;

use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\TaskInput;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\QuickAddParser;
use App\Services\RankingService;
use App\Services\Reminders;
use App\Services\Repeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes a task, shared by the screens and the API so both
 * always behave the same: adding (also from plain words), changing, done and
 * not done (with the repeat copy), deleting and restoring.
 */
class TaskActions
{
    public function __construct(
        private readonly RankingService $ranking,
        private readonly QuickAddParser $parser,
        private readonly PrioritizationEngine $engine,
    ) {}

    /** The fields a task can be changed with (dates as typed, in the user's time zone). */
    public static function updateRules(User $user): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'importance' => ['sometimes', 'integer', 'between:1,5'],
            'urgency' => ['sometimes', 'integer', 'between:1,5'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'est_hours' => ['sometimes', 'numeric', 'min:0.05', 'max:500'],
            'energy' => ['sometimes', 'nullable', Rule::in(Task::ENERGY)],
            'status' => ['sometimes', Rule::in([Task::TODO, Task::DOING, Task::WAITING])],
            'waiting_for' => ['sometimes', 'nullable', 'string', 'max:160'],
            'follow_up_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'goal_id' => ['sometimes', 'nullable', 'integer', Rule::exists('goals', 'id')->where('user_id', $user->id)->whereNull('archived_at')],
            'repeat_unit' => ['sometimes', 'nullable', Rule::in(Repeat::UNITS)],
            'repeat_every' => ['sometimes', 'integer', 'between:1,99'],
            'repeat_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'repeat_days.*' => ['integer', 'between:1,7', 'distinct'],
            'repeat_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'reminder_at' => ['sometimes', 'nullable', 'date'],
            'depends_on' => ['sometimes', 'array', 'max:20'],
            'depends_on.*' => ['integer', 'distinct', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
        ];
    }

    /**
     * Plain words in: "Ethics essay outline fri 3h #fyp" → a task due Friday, 3 hours, in the FYP project.
     * "from mon" sets when it can start, "remind 9am" adds a reminder, "!" / "!!" the importance
     * (importance picked on the dots wins). $plain: words to keep as words.
     *
     * @param  list<string>  $plain
     */
    public function quickAdd(User $user, string $text, ?int $importance = null, ?int $urgency = null, ?int $projectId = null, array $plain = [], bool $capture = false): Task
    {
        $parsed = $this->parse($user, $text, $plain);
        if ($parsed['title'] === '') {
            throw ValidationException::withMessages(['text' => 'A task needs a title, not only a date or hours.']);
        }

        $task = $this->create($user, [
            'title' => $parsed['title'],
            'importance' => $importance ?? $parsed['importance'] ?? 3,
            'urgency' => $urgency ?? 3,
            'due_at' => $parsed['due']?->utc(),
            'est_hours' => $parsed['hours'] ?? 1,
            'project_id' => $projectId ?? $parsed['projectId'],
            'repeat_unit' => $parsed['repeat']['unit'] ?? null,
            'repeat_every' => $parsed['repeat']['every'] ?? 1,
            'repeat_days' => $parsed['repeat']['days'] ?? null,
            'repeat_until' => $parsed['repeat']['until'] ?? null,
            'repeat_anchor' => $parsed['repeat'] ? $parsed['due']?->utc() : null,
            'available_from' => $parsed['start']?->utc(),
            'energy' => $parsed['energy'] ?? null,
            'captured_at' => $capture ? $this->ranking->now() : null,
        ]);
        if ($parsed['remind']) {
            Reminders::add($task, $parsed['remind']['kind'] === 'at' ? ['kind' => 'at', 'at' => $parsed['remind']['at']->utc()] : $parsed['remind'], $this->ranking->now());
        }

        return $task;
    }

    /** What quick add makes of the words: title, due, hours, project, repeat, start, reminder, importance. */
    public function parse(User $user, string $text, array $plain = []): array
    {
        $plain = array_values(array_filter(array_map(fn ($w) => mb_strtolower(rtrim(trim((string) $w), ',.')), $plain)));

        return $this->parser->parse($text, $this->ranking->now()->timezone($user->timezone), $user->projects()->pluck('name', 'id')->all(), $plain);
    }

    public function create(User $user, array $fields): Task
    {
        $task = $user->tasks()->create($fields);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created']);

        return $task;
    }

    /**
     * Saves a change (already checked, dates already in UTC). Returns the history
     * entry that Undo puts back, or null when nothing changed.
     */
    public function update(Task $task, User $user, array $data): ?TaskEvent
    {
        $deps = null;
        if (array_key_exists('depends_on', $data)) {
            $deps = ['before' => $task->dependencies()->pluck('tasks.id')->all(), 'after' => array_map('intval', $data['depends_on'])];
            $this->setDependencies($task, $data['depends_on']);
            unset($data['depends_on']);
        }
        // A task in a project has the project's goal: moving it into one drops its own.
        if (! empty($data['project_id']) && $task->goal_id) {
            $data['goal_id'] = null;
        }
        if (! empty($data['goal_id']) && (array_key_exists('project_id', $data) ? $data['project_id'] : $task->project_id)) {
            throw ValidationException::withMessages(['goal_id' => 'A task in a project has the project’s goal.']);
        }
        // The single "reminder_at" field (the API's): one "at a time" reminder then, or none.
        if (array_key_exists('reminder_at', $data)) {
            Reminders::setAt($task, $data['reminder_at'] ? CarbonImmutable::instance($data['reminder_at']) : null, $this->ranking->now());
            unset($data['reminder_at']);
        }
        // Repeats: a new rule starts the series again from this task's due date; a new due date on its own moves
        // only this copy (the next one still comes when it was meant to).
        if (array_key_exists('repeat_days', $data)) {
            $days = array_values(array_unique(array_map('intval', $data['repeat_days'] ?? [])));
            sort($days);
            $data['repeat_days'] = $days ?: null;
        }
        if (array_intersect_key($data, array_flip(['repeat_unit', 'repeat_every', 'repeat_days'])) !== []) {
            $data['repeat_day'] = null;
            $data['repeat_anchor'] = array_key_exists('due_at', $data) ? $data['due_at'] : $task->due_at;
            $unit = array_key_exists('repeat_unit', $data) ? $data['repeat_unit'] : $task->repeat_unit;
            if ($unit !== 'week') {
                $data['repeat_days'] = null;
            }
            if ($unit === null) {
                $data['repeat_until'] = null;
                $data['repeat_anchor'] = null;
            }
        } elseif (array_key_exists('due_at', $data) && $task->repeat_unit && ! $task->repeat_anchor) {
            $data['repeat_anchor'] = $task->due_at; // an older copy: the series stays where it was
        }
        // A done or cancelled task is opened again only through reopen, which also handles its repeat copy.
        if (in_array($task->status, Task::FINISHED, true)) {
            unset($data['status']);
        }
        // Waiting from now ("waiting for Dr. Lim · 5 days"); any other status forgets it.
        if (isset($data['status']) && $data['status'] !== $task->status) {
            $data['waiting_since'] = $data['status'] === Task::WAITING ? $this->ranking->now() : null;
        }
        if (! $data && ! $deps) {
            return null;
        }
        $before = $task->only(array_keys($data));
        $task->update($data);

        return $task->events()->create(['user_id' => $user->id, 'type' => 'updated', 'snapshot' => [
            'before' => $before, 'after' => $task->only(array_keys($data)), 'depends_on' => $deps,
        ]]);
    }

    /**
     * "Starts after …": the task can't start until these are done. A task can't
     * wait for itself, or for a task that (through others) waits for it.
     *
     * @param  list<int>  $ids
     */
    public function setDependencies(Task $task, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $loops = in_array($task->id, $ids, true);
        $seen = [];
        $stack = $ids;
        while (! $loops && $stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $next = DB::table('task_dependencies')->where('task_id', $id)->pluck('depends_on_id')->all();
            $loops = in_array($task->id, $next, true);
            array_push($stack, ...$next);
        }
        if ($loops) {
            throw ValidationException::withMessages(['depends_on' => 'That would make these tasks wait for each other.']);
        }
        $task->dependencies()->sync($ids);
    }

    /**
     * Marks one open task done with the score it had (looked up when not given),
     * and returns its next repeat copy if it repeats.
     */
    public function complete(Task $task, User $user, ?float $score = null, bool $lookUp = true): ?Task
    {
        $score ??= $lookUp ? $this->ranking->rank($user)->scoreOf($task->id) : null;
        $was = $task->status;
        $task->update(['status' => Task::DONE, 'completed_at' => $this->ranking->now(), 'score_at_completion' => $score]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'completed', 'snapshot' => ['score' => $score, 'was' => $was]]);

        return $this->repeatNext($task, $user);
    }

    /**
     * Cancels an open task: it won't be done. A running focus on it stops; its
     * planner blocks, reminders and follow-up stop counting because it isn't
     * open any more. With $next a repeating task skips just this one and its
     * next copy is made.
     */
    public function cancel(Task $task, User $user, ?string $reason = null, bool $next = false): ?Task
    {
        $running = FocusActions::running($user);
        if ($running && (int) $running->task_id === $task->id) {
            app(FocusActions::class)->finish($running);
        }
        $was = $task->status;
        $task->update(['status' => Task::CANCELLED, 'cancelled_at' => $this->ranking->now(), 'cancel_reason' => $reason]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'cancelled', 'snapshot' => ['reason' => $reason, 'was' => $was]]);

        return $next ? $this->repeatNext($task, $user) : null;
    }

    /** A done or cancelled task out of the lists (it still counts in History and Review). */
    public function archive(Task $task, User $user): void
    {
        $task->update(['archived_at' => $this->ranking->now()]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'archived']);
    }

    public function unarchive(Task $task, User $user): void
    {
        $task->update(['archived_at' => null, 'unarchived_at' => $this->ranking->now()]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'unarchived']);
    }

    /** Opens a done or cancelled task again, as it was before, and takes back the repeat copy it made. */
    public function reopen(Task $task, User $user, ?string $status = null): void
    {
        // Back to what it was before it was ticked off or cancelled (Doing, Waiting …), or To do, unless a status
        // is asked for (a card dropped from "Done today" onto another column of the board).
        $was = $status ?? $task->events()->whereIn('type', ['completed', 'cancelled'])->first()?->snapshot['was'] ?? Task::TODO;
        $task->update([
            'status' => in_array($was, Task::OPEN, true) ? $was : Task::TODO, 'completed_at' => null, 'score_at_completion' => null,
            'cancelled_at' => null, 'cancel_reason' => null, 'archived_at' => null,
        ]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'reopened']);
        // Undoing "done" on a repeating task takes back the copy it created, if that copy is still open.
        $next = $task->nextRepeat()->open()->first();
        if ($next) {
            $next->events()->create(['user_id' => $user->id, 'type' => 'deleted']);
            $next->delete();
        }
    }

    /**
     * Deletes a task (Undo can bring it back). With $children its subtasks go too, and
     * come back with it; without, they stay as tasks of their own.
     */
    public function delete(Task $task, User $user, bool $children = false): void
    {
        $kids = $children ? $task->children()->get() : collect();
        $task->events()->create(['user_id' => $user->id, 'type' => 'deleted', 'snapshot' => $kids->isNotEmpty() ? ['children' => $kids->pluck('id')->all()] : null]);
        foreach ($kids as $kid) {
            $kid->events()->create(['user_id' => $user->id, 'type' => 'deleted', 'snapshot' => ['withParent' => $task->id]]);
            $kid->delete();
        }
        $task->delete();
    }

    public function restore(Task $task, User $user): void
    {
        $deleted = $task->events()->where('type', 'deleted')->first();
        $task->restore();
        $task->events()->create(['user_id' => $user->id, 'type' => 'restored']);
        // Subtasks deleted with it come back with it.
        $ids = $deleted?->snapshot['children'] ?? [];
        if ($ids) {
            Task::onlyTrashed()->where('user_id', $user->id)->whereIn('id', $ids)->get()->each(fn (Task $kid) => $this->restore($kid, $user));
        }
    }

    /** Scores a task that is not saved yet and finds its place among the others (leaving out $except). */
    public function place(TaskInput $input, User $user, ?int $except = null): array
    {
        $list = $this->ranking->rank($user);
        $scored = $this->engine->score($input, $this->ranking->now()->toDateTimeImmutable(), $list->params);
        $others = array_values(array_filter($list->scored, fn ($s) => (int) $s->task->id !== $except));
        $ahead = array_values(array_filter($others, fn ($s) => PrioritizationEngine::compare($s, $scored) < 0));

        return [
            'rank' => count($ahead) + 1,
            'total' => count($others) + 1,
            'score' => $scored->displayScore(),
            'contrib' => array_map(fn ($v) => Rounding::halfUp($v, 1), $scored->contributions),
            'criteria' => array_map(fn ($v) => round($v, 4), $scored->criteria),
            'above' => $ahead ? $list->tasks[(int) end($ahead)->task->id]->title : null,
            'params' => ['weights' => $list->params->weights->toPercentages(), 'horizonDays' => $list->params->horizonDays, 'hoursPerDay' => $list->params->hoursPerDay],
        ];
    }

    /**
     * A finished repeating task is followed by a fresh copy with the next due date
     * (its steps start again, undone). The copy is an ordinary task: the formula
     * ranks it like any other.
     */
    private function repeatNext(Task $task, User $user): ?Task
    {
        if (! $task->repeat_unit || $task->nextRepeat()->exists()) {
            return null;
        }
        $now = $this->ranking->now();
        // From when this copy was meant to be due (moving one doesn't move the rest); with no due date, the end of today.
        $from = $task->repeat_anchor ?? $task->due_at;
        $base = $from ? CarbonImmutable::instance($from) : $now->timezone($user->timezone)->setTime(23, 59);
        $day = $task->repeat_day ?? Repeat::anchorDay($base, $task->repeat_unit, $user->timezone);
        $due = Repeat::next($base, $task->repeat_unit, $task->repeat_every, $user->timezone, $now, $day, $task->repeat_days);
        // The last one: no copy after the end date.
        if ($task->repeat_until && $due->timezone($user->timezone)->toDateString() > $task->repeat_until->toDateString()) {
            return null;
        }
        $shift = fn ($at) => $at ? CarbonImmutable::instance($at)->addSeconds($due->getTimestamp() - $base->getTimestamp()) : null;

        $next = $user->tasks()->create([
            'project_id' => $task->project_id,
            'goal_id' => $task->goal_id,
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'notes' => $task->notes,
            'importance' => $task->importance,
            'urgency' => $task->urgency,
            'due_at' => $due,
            'est_hours' => $task->est_hours,
            'energy' => $task->energy,
            'available_from' => $shift($task->available_from),
            'repeat_unit' => $task->repeat_unit,
            'repeat_every' => $task->repeat_every,
            'repeat_days' => $task->repeat_days,
            'repeat_until' => $task->repeat_until,
            'repeat_anchor' => $due,
            'repeat_day' => $day,
            'repeated_from_id' => $task->id,
        ]);
        foreach ($task->steps as $step) {
            $next->steps()->create(['title' => $step->title, 'position' => $step->position]);
        }
        Reminders::copy($task, $next, $due->getTimestamp() - $base->getTimestamp());
        $next->events()->create(['user_id' => $user->id, 'type' => 'created', 'snapshot' => ['repeatOf' => $task->id]]);

        return $next;
    }
}
