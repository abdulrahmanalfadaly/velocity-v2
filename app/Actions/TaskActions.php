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
            'status' => ['sometimes', Rule::in([Task::TODO, Task::DOING, Task::WAITING])],
            'waiting_for' => ['sometimes', 'nullable', 'string', 'max:160'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'repeat_unit' => ['sometimes', 'nullable', Rule::in(Repeat::UNITS)],
            'repeat_every' => ['sometimes', 'integer', 'between:1,99'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'reminder_at' => ['sometimes', 'nullable', 'date'],
            'depends_on' => ['sometimes', 'array', 'max:20'],
            'depends_on.*' => ['integer', 'distinct', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
        ];
    }

    /** Plain words in: "Ethics essay outline fri 3h #fyp" → a task due Friday, 3 hours, in the FYP project. */
    public function quickAdd(User $user, string $text, ?int $importance = null, ?int $urgency = null, ?int $projectId = null): Task
    {
        $parsed = $this->parse($user, $text);
        if ($parsed['title'] === '') {
            throw ValidationException::withMessages(['text' => 'A task needs a title, not only a date or hours.']);
        }

        return $this->create($user, [
            'title' => $parsed['title'],
            'importance' => $importance ?? 3,
            'urgency' => $urgency ?? 3,
            'due_at' => $parsed['due']?->utc(),
            'est_hours' => $parsed['hours'] ?? 1,
            'project_id' => $projectId ?? $parsed['projectId'],
            'repeat_unit' => $parsed['repeat']['unit'] ?? null,
            'repeat_every' => $parsed['repeat']['every'] ?? 1,
        ]);
    }

    /** What quick add makes of the words: title, due, hours, project, repeat. */
    public function parse(User $user, string $text): array
    {
        return $this->parser->parse($text, $this->ranking->now()->timezone($user->timezone), $user->projects()->pluck('name', 'id')->all());
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
        // A new reminder time pops up again, even if an earlier one was already shown.
        if (array_key_exists('reminder_at', $data)) {
            $data['reminder_sent_at'] = null;
        }
        // A new due date or repeat rule sets a new day of the month to keep to.
        if (array_key_exists('due_at', $data) || array_key_exists('repeat_unit', $data)) {
            $data['repeat_day'] = null;
        }
        // A done task is opened again only through "Not done" (reopen), which also handles its repeat copy.
        if ($task->status === Task::DONE) {
            unset($data['status']);
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

    /** Opens a done task again, as it was before, and takes back the repeat copy it made. */
    public function reopen(Task $task, User $user, ?string $status = null): void
    {
        // Back to what it was before it was ticked off (Doing, Waiting …), or To do, unless a status is asked for
        // (a card dropped from "Done today" onto another column of the board).
        $was = $status ?? $task->events()->where('type', 'completed')->first()?->snapshot['was'] ?? Task::TODO;
        $task->update(['status' => in_array($was, Task::OPEN, true) ? $was : Task::TODO, 'completed_at' => null, 'score_at_completion' => null]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'reopened']);
        // Undoing "done" on a repeating task takes back the copy it created, if that copy is still open.
        $next = $task->nextRepeat()->open()->first();
        if ($next) {
            $next->events()->create(['user_id' => $user->id, 'type' => 'deleted']);
            $next->delete();
        }
    }

    public function delete(Task $task, User $user): void
    {
        $task->events()->create(['user_id' => $user->id, 'type' => 'deleted']);
        $task->delete();
    }

    public function restore(Task $task, User $user): void
    {
        $task->restore();
        $task->events()->create(['user_id' => $user->id, 'type' => 'restored']);
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
        // A task with no due date repeats from the end of today.
        $base = $task->due_at ? CarbonImmutable::instance($task->due_at) : $now->timezone($user->timezone)->setTime(23, 59);
        $day = $task->repeat_day ?? Repeat::anchorDay($base, $task->repeat_unit, $user->timezone);
        $due = Repeat::next($base, $task->repeat_unit, $task->repeat_every, $user->timezone, $now, $day);
        $shift = fn ($at) => $at ? CarbonImmutable::instance($at)->addSeconds($due->getTimestamp() - $base->getTimestamp()) : null;

        $next = $user->tasks()->create([
            'project_id' => $task->project_id,
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'notes' => $task->notes,
            'importance' => $task->importance,
            'urgency' => $task->urgency,
            'due_at' => $due,
            'est_hours' => $task->est_hours,
            'available_from' => $shift($task->available_from),
            'reminder_at' => $shift($task->reminder_at),
            'repeat_unit' => $task->repeat_unit,
            'repeat_every' => $task->repeat_every,
            'repeat_day' => $day,
            'repeated_from_id' => $task->id,
        ]);
        foreach ($task->steps as $step) {
            $next->steps()->create(['title' => $step->title, 'position' => $step->position]);
        }
        $next->events()->create(['user_id' => $user->id, 'type' => 'created', 'snapshot' => ['repeatOf' => $task->id]]);

        return $next;
    }
}
