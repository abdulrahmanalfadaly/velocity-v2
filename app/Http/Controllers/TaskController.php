<?php

namespace App\Http\Controllers;

use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\TaskInput;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\User;
use App\Services\QuickAddParser;
use App\Services\RankingService;
use App\Services\Repeat;
use App\Services\StudyLogger;
use App\Services\TaskPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    /** Every task you can work on, in priority order. */
    public function index(Request $request, StudyLogger $log): Response
    {
        $user = $request->user();
        if (! $user->fyp_mode) {
            return $this->personalList($request, 'upnext');
        }

        $list = $this->ranking->rank($user);
        $log->ranking($user, $list, 'tasks');

        return Inertia::render('fyp/tasks', [
            'tasks' => $list->toArray(),
            'settings' => $list->settings(),
            'completed' => $user->tasks()->where('status', Task::DONE)->latest('completed_at')->limit(8)->get()->map(fn ($t) => TaskPresenter::base($t)),
            'selected' => fn () => $this->detail($request),
        ]);
    }

    /** Ranked tasks that are not in a project yet (they keep their place in the ranking). */
    public function inbox(Request $request): Response
    {
        return $this->personalList($request, 'inbox');
    }

    /** Open tasks that can't start yet: waiting on someone, after another task, or starting later. */
    public function waiting(Request $request): Response
    {
        return $this->personalList($request, 'waiting');
    }

    /** The personal Tasks page; $view picks Up next, Inbox or Not ready yet. */
    private function personalList(Request $request, string $view): Response
    {
        $user = $request->user();
        $list = $this->ranking->rank($user);
        $now = $this->ranking->now()->timezone($user->timezone);

        return Inertia::render('tasks/index', [
            'view' => $view,
            'tasks' => $list->toArray(),
            'settings' => $list->settings(),
            'notReady' => $this->ranking->notReady($user)->map(fn ($r) => TaskPresenter::notReady($r))->values(),
            'doneToday' => $user->tasks()->where('status', Task::DONE)->where('completed_at', '>=', $now->startOfDay()->utc())
                ->with('project.goal')->latest('completed_at')->get()->map(fn ($t) => TaskPresenter::base($t)),
            'selected' => fn () => $this->detail($request),
            'projects' => $user->projects()->whereNull('archived_at')->get(['id', 'name']),
        ]);
    }

    /** Full details for the task panel (?task=id), including its place in the ranking. Also used by project pages. */
    public function detailFor(Request $request): ?array
    {
        return $this->detail($request);
    }

    private function detail(Request $request): ?array
    {
        $id = (int) $request->query('task');
        if (! $id) {
            return null;
        }
        $user = $request->user();
        $task = Task::with(['project.goal', 'parent:id,title', 'steps', 'children', 'dependencies', 'files', 'linkedNotes.event', 'events' => fn ($q) => $q->limit(8)])
            ->where('user_id', $user->id)->find($id);
        if (! $task) {
            return null;
        }
        $list = $this->ranking->rank($user);
        $k = $list->rankOf($task->id);
        $base = $k ? $list->present($k - 1) : TaskPresenter::base($task);

        return $base + [
            'total' => count($list),
            'children' => $task->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'rank' => $list->rankOf($c->id), 'score' => $list->scoreOf($c->id)])->values(),
            'dependsOn' => $task->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values(),
            // Open tasks this one could be set to start after.
            'files' => $task->files->map(fn ($f) => $f->present())->values(),
            'linkedNotes' => $task->linkedNotes->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle()])->values(),
            'focused' => ['seconds' => (int) $task->focusSessions()->whereNotNull('ended_at')->sum('seconds'), 'sessions' => $task->focusSessions()->whereNotNull('ended_at')->count()],
            'candidates' => $user->tasks()->open()->whereKeyNot($task->id)->orderBy('title')->limit(300)->get(['id', 'title'])->values(),
            'goal' => $task->project?->goal ? ['id' => $task->project->goal->id, 'title' => $task->project->goal->title, 'color' => $task->project->goal->color] : null,
            'events' => $task->events->map(fn ($e) => ['type' => $e->type, 'at' => $e->created_at?->toIso8601ZuluString()])->values(),
        ];
    }

    /** Quick add (plain words in) or the FYP task form (one field per attribute, FR2). */
    public function store(Request $request, QuickAddParser $parser): RedirectResponse
    {
        $user = $request->user();
        if (! $request->has('text')) {
            $task = $user->tasks()->create($this->formFields($request));
            $task->events()->create(['user_id' => $user->id, 'type' => 'created']);

            return back()->with('toast', $this->placed($task, 'Added'))->with('undo', $this->undo('delete-task', $task->id));
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'importance' => ['nullable', 'integer', 'between:1,5'],
            'urgency' => ['nullable', 'integer', 'between:1,5'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ]);
        $parsed = $parser->parse($data['text'], $this->ranking->now()->timezone($user->timezone), $user->projects()->pluck('name', 'id')->all());
        if ($parsed['title'] === '') {
            throw ValidationException::withMessages(['text' => 'A task needs a title, not only a date or hours.']);
        }

        $task = $user->tasks()->create([
            'title' => $parsed['title'],
            'importance' => $data['importance'] ?? 3,
            'urgency' => $data['urgency'] ?? 3,
            'due_at' => $parsed['due']?->utc(),
            'est_hours' => $parsed['hours'] ?? 1,
            'project_id' => $data['project_id'] ?? $parsed['projectId'],
            'repeat_unit' => $parsed['repeat']['unit'] ?? null,
            'repeat_every' => $parsed['repeat']['every'] ?? 1,
        ]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created']);
        $rank = $this->ranking->rank($user)->rankOf($task->id);

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** Where a task will land, before it is saved: from quick-add words or from the FYP form's fields. */
    public function preview(Request $request, QuickAddParser $parser, PrioritizationEngine $engine): JsonResponse
    {
        $user = $request->user();
        $now = $this->ranking->now()->toDateTimeImmutable();

        if (! $request->has('text')) {
            $data = $request->validate([
                'importance' => ['required', 'integer', 'between:1,5'],
                'urgency' => ['required', 'integer', 'between:1,5'],
                'due_at' => ['nullable', 'date'],
                'est_hours' => ['required', 'numeric', 'min:0.05', 'max:500'],
                'except' => ['nullable', 'integer'],
            ]);
            $input = new TaskInput(0, 'preview', (int) $data['importance'], (int) $data['urgency'], $this->fromLocal($data['due_at'] ?? null, $user)?->toDateTimeImmutable(), (float) $data['est_hours'], $now);

            return response()->json(['preview' => $this->place($input, $engine, $user, $data['except'] ?? null)]);
        }

        $data = $request->validate(['text' => ['required', 'string', 'max:255'], 'importance' => ['integer', 'between:1,5'], 'urgency' => ['integer', 'between:1,5']]);
        $parsed = $parser->parse($data['text'], $this->ranking->now()->timezone($user->timezone), $user->projects()->pluck('name', 'id')->all());
        if ($parsed['title'] === '') {
            return response()->json(['parsed' => $parsed, 'preview' => null]);
        }
        $input = new TaskInput(0, $parsed['title'], (int) ($data['importance'] ?? 3), (int) ($data['urgency'] ?? 3), $parsed['due']?->toDateTimeImmutable(), $parsed['hours'] ?? 1.0, $now);

        return response()->json([
            'parsed' => [
                'title' => $parsed['title'],
                'due' => $parsed['due']?->utc()->toIso8601ZuluString(),
                'hours' => $parsed['hours'],
                'project' => $parsed['projectId'] ? $user->projects()->find($parsed['projectId'])?->only('id', 'name') : null,
                'repeat' => $parsed['repeat'],
                'picked' => $parsed['picked'],
            ],
            'preview' => $this->place($input, $engine, $user),
        ]);
    }

    /** Scores a task that is not saved yet and finds its place among the others (leaving out $except). */
    private function place(TaskInput $input, PrioritizationEngine $engine, User $user, ?int $except = null): array
    {
        $list = $this->ranking->rank($user);
        $scored = $engine->score($input, $this->ranking->now()->toDateTimeImmutable(), $list->params);
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

    public function update(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if ($request->boolean('form')) {
            $data = $this->formFields($request);
        } else {
            $data = $request->validate([
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
            ], [], ['depends_on.*' => 'task', 'available_from' => 'start date']);
            foreach (['due_at', 'available_from', 'reminder_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = $this->fromLocal($data[$key], $user);
                }
            }
        }
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
            return back();
        }
        $before = $task->only(array_keys($data));
        $task->update($data);
        $event = $task->events()->create(['user_id' => $user->id, 'type' => 'updated', 'snapshot' => [
            'before' => $before, 'after' => $task->only(array_keys($data)), 'depends_on' => $deps,
        ]]);
        $changed = array_keys(array_diff_key($data, ['repeat_day' => 1, 'repeat_every' => 1, 'reminder_sent_at' => 1]));
        $what = $deps ? 'Starts after' : (self::LABELS[$changed[0] ?? ''] ?? 'Task');
        $toast = $request->boolean('form') ? $this->placed($task, 'Saved') : "{$what} changed";

        return back()->with('toast', $toast)->with('undo', $this->undo('revert-task', $task->id, ['event' => $event->id]));
    }

    private const LABELS = [
        'title' => 'Title', 'notes' => 'Notes', 'importance' => 'Importance', 'urgency' => 'Urgency', 'due_at' => 'Due date',
        'est_hours' => 'Work', 'status' => 'Status', 'waiting_for' => 'Waiting for', 'project_id' => 'Project',
        'repeat_unit' => 'Repeat', 'available_from' => 'Start date', 'reminder_at' => 'Reminder',
    ];

    /** Undo of one change in the task panel: put back what the saved event remembers. */
    public function revert(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $event = $task->events()->where('type', 'updated')->findOrFail($request->integer('event'));
        $snap = $event->snapshot;
        if (! empty($snap['depends_on'])) {
            $this->setDependencies($task, $snap['depends_on']['before']);
        }
        if (! empty($snap['before'])) {
            $task->update($snap['before']);
        }
        $task->events()->create(['user_id' => $user->id, 'type' => 'reverted', 'snapshot' => ['event' => $event->id]]);

        return back()->with('toast', 'Change undone');
    }

    /** Undo of "Make it a task": the step comes back and the task it became goes away. */
    public function unpromote(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $stepId = $task->events()->where('type', 'promoted')->first()?->snapshot['from_step'];
        $step = $stepId ? TaskStep::withTrashed()->find($stepId) : null;
        abort_unless($step && $step->task && (int) $step->task->user_id === $user->id, 404);
        $step->restore();
        $task->forceDelete();

        return back()->with('toast', "“{$step->title}” is a step again");
    }

    /**
     * "Starts after …": the task can't start until these are done. A task can't
     * wait for itself, or for a task that (through others) waits for it.
     *
     * @param  list<int>  $ids
     */
    private function setDependencies(Task $task, array $ids): void
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

    /** The FYP task form: title, notes, importance, urgency, optional due date, estimated hours (FR2). */
    private function formFields(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'importance' => ['required', 'integer', 'between:1,5'],
            'urgency' => ['required', 'integer', 'between:1,5'],
            'due_at' => ['nullable', 'date'],
            'est_hours' => ['required', 'numeric', 'min:0.05', 'max:500'],
        ], [], ['est_hours' => 'estimated duration', 'due_at' => 'due date']);
        $data['due_at'] = $this->fromLocal($data['due_at'] ?? null, $request->user());

        return $data;
    }

    /** "Added “X” – ranked #3 of 11 with a score of 61.0." */
    private function placed(Task $task, string $verb): string
    {
        $list = $this->ranking->rank($task->user);
        $rank = $list->rankOf($task->id);

        return $rank
            ? sprintf('%s “%s” – ranked #%d of %d with a score of %.1f.', $verb, $task->title, $rank, count($list), $list->scoreOf($task->id))
            : "{$verb} “{$task->title}”.";
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        // Already done (a double click, an old tab): change nothing, so its score at completion is kept.
        if ($task->status === Task::DONE) {
            return back();
        }
        $next = $this->finish($task, $user, $this->ranking->rank($user)->scoreOf($task->id));
        $toast = "Done: “{$task->title}”".($next ? ' · next one '.$next->due_at->timezone($user->timezone)->format('D j M') : '');

        return back()->with('toast', $toast)->with('undo', ['action' => 'reopen', 'id' => $task->id]);
    }

    /** Marks one open task done with the score it had, and returns its next repeat copy (if it repeats). */
    private function finish(Task $task, User $user, ?float $score): ?Task
    {
        $was = $task->status;
        $task->update(['status' => Task::DONE, 'completed_at' => $this->ranking->now(), 'score_at_completion' => $score]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'completed', 'snapshot' => ['score' => $score, 'was' => $was]]);

        return $this->repeatNext($task, $user);
    }

    /** Opens a done task again, as it was before, and takes back the repeat copy it made. */
    private function unfinish(Task $task, User $user, ?string $status = null): void
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

    /**
     * One action on several tasks at once (the selection bar on the task lists):
     * done, delete, move to a project, or set a due date. Undo puts every task back.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['complete', 'delete', 'move', 'due'])],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'due_at' => ['nullable', 'date'],
        ]);
        // Only the user's own open tasks; anything else in the list is quietly left out.
        $tasks = $user->tasks()->open()->whereIn('id', $data['ids'])->get();
        if ($tasks->isEmpty()) {
            return back();
        }
        $n = $tasks->count();
        $noun = $n === 1 ? 'task' : 'tasks';
        $before = [];

        switch ($data['action']) {
            case 'complete':
                $list = $this->ranking->rank($user); // one ranking: each task keeps the score it had at this moment
                $tasks->each(fn (Task $t) => $this->finish($t, $user, $list->scoreOf($t->id)));
                $toast = "{$n} {$noun} done";
                break;
            case 'delete':
                $tasks->each(function (Task $t) use ($user) {
                    $t->events()->create(['user_id' => $user->id, 'type' => 'deleted']);
                    $t->delete();
                });
                $toast = "Deleted {$n} {$noun}";
                break;
            case 'move':
                $projectId = $data['project_id'] ?? null;
                foreach ($tasks as $t) {
                    $before[$t->id] = ['project_id' => $t->project_id];
                    $t->update(['project_id' => $projectId]);
                }
                $toast = "Moved {$n} {$noun}";
                break;
            default: // due
                $due = $this->fromLocal($data['due_at'] ?? null, $user);
                foreach ($tasks as $t) {
                    $before[$t->id] = ['due_at' => $t->due_at?->toIso8601ZuluString(), 'repeat_day' => $t->repeat_day];
                    $t->update(['due_at' => $due, 'repeat_day' => null]);
                }
                $toast = $due ? "Due date set on {$n} {$noun}" : "Due date cleared on {$n} {$noun}";
        }

        return back()->with('toast', $toast)
            ->with('undo', $this->undo('bulk-undo', 0, ['action' => $data['action'], 'ids' => $tasks->pluck('id')->all(), 'before' => $before]));
    }

    /** Undo of a bulk action: not done again, restored, or back to each task's old project / due date. */
    public function bulkUndo(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['complete', 'delete', 'move', 'due'])],
            'before' => ['nullable', 'array'],
            'before.*.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'before.*.due_at' => ['nullable', 'date'],
            'before.*.repeat_day' => ['nullable', 'integer', 'between:1,31'],
        ]);
        $tasks = Task::withTrashed()->where('user_id', $user->id)->whereIn('id', $data['ids'])->get();

        foreach ($tasks as $t) {
            if ($data['action'] === 'complete' && $t->status === Task::DONE) {
                $this->unfinish($t, $user);
            } elseif ($data['action'] === 'delete' && $t->trashed()) {
                $t->restore();
                $t->events()->create(['user_id' => $user->id, 'type' => 'restored']);
            } elseif (in_array($data['action'], ['move', 'due'], true) && isset($data['before'][$t->id])) {
                $t->update(array_intersect_key($data['before'][$t->id], ['project_id' => 1, 'due_at' => 1, 'repeat_day' => 1]));
            }
        }

        return back()->with('toast', 'Undone');
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

    public function reopen(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if ($task->status !== Task::DONE) {
            return back();
        }
        $status = $request->validate(['status' => ['sometimes', Rule::in(Task::OPEN)]])['status'] ?? null;
        $this->unfinish($task, $user, $status);

        return back()->with('toast', "Not done: “{$task->title}”")->with('undo', $this->undo('complete', $task->id));
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $task->events()->create(['user_id' => $user->id, 'type' => 'deleted']);
        $task->delete();

        return redirect()->to(url()->previous() === url()->current() ? route('tasks.index') : preg_replace('/([?&])task=\d+&?/', '$1', url()->previous()))
            ->with('toast', "Deleted “{$task->title}”")->with('undo', ['action' => 'restore', 'id' => $task->id]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $task = Task::withTrashed()->where('user_id', $user->id)->findOrFail($id);
        $task->restore();
        $task->events()->create(['user_id' => $user->id, 'type' => 'restored']);

        return back()->with('toast', "Restored “{$task->title}”")->with('undo', $this->undo('delete-task', $task->id));
    }
}
