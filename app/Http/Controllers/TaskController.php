<?php

namespace App\Http\Controllers;

use App\Actions\TaskActions;
use App\Domain\Prioritization\TaskInput;
use App\Models\GithubLink;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\User;
use App\Services\AutoArchive;
use App\Services\FollowUps;
use App\Services\GitHub;
use App\Services\NextActions;
use App\Services\RankingService;
use App\Services\StudyLogger;
use App\Services\TaskLinks;
use App\Services\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private readonly RankingService $ranking, private readonly TaskActions $actions) {}

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

    /** The Inbox is a chip on Up next now (tasks with no project, and captured ones to sort): old links land there. */
    public function inbox(Request $request): RedirectResponse
    {
        return to_route('tasks.index', array_filter(['show' => 'inbox', 'task' => $request->query('task')]));
    }

    /** Open tasks that can't start yet: waiting on someone, after another task, or starting later. */
    public function waiting(Request $request): Response
    {
        return $this->personalList($request, 'waiting');
    }

    /** The personal Tasks page; $view picks Up next or Not ready yet. */
    private function personalList(Request $request, string $view): Response
    {
        $user = $request->user();
        $list = $this->ranking->rank($user);
        $now = $this->ranking->now()->timezone($user->timezone);
        AutoArchive::run($user, $this->ranking->now());

        return Inertia::render('tasks/index', [
            'view' => $view,
            // A view of the list ("?show=today"): the same ranking with some tasks left out.
            'show' => in_array($request->query('show'), self::SHOWS, true) ? $request->query('show') : 'all',
            'tasks' => $list->toArray(),
            'settings' => $list->settings(),
            'notReady' => $this->ranking->notReady($user)->map(fn ($r) => TaskPresenter::notReady($r))->values(),
            'followUps' => FollowUps::due($user, $now->startOfDay())->map(fn (Task $t) => FollowUps::present($t, $now->startOfDay(), $list->rankOf($t->id)))->values(),
            'doneToday' => $user->tasks()->where('status', Task::DONE)->whereNull('archived_at')->where('completed_at', '>=', $now->startOfDay()->utc())
                ->with('project.goal')->latest('completed_at')->get()->map(fn ($t) => TaskPresenter::base($t)),
            'selected' => fn () => $this->detail($request),
            'projects' => $user->projects()->whereNull('archived_at')->get(['id', 'name']),
            // Captured only, waiting to be sorted (the Inbox's "To sort"), newest first.
            'captured' => $user->tasks()->whereIn('status', Task::OPEN)->whereNotNull('captured_at')->with('project.goal')->latest('captured_at')->get()
                ->map(fn ($t) => TaskPresenter::base($t)),
            // For the template button in "Add a task": loaded only when it is pressed.
            'templates' => Inertia::optional(fn () => $user->templates()->with('project:id,name')->get()->map(fn ($t) => TemplateController::present($t))->values()),
        ]);
    }

    private const SHOWS = ['all', 'inbox', 'today', 'week', 'overdue', 'quick', 'easy', 'doing', 'important', 'repeating'];

    /** Tabs of All tasks. */
    public const ALL_TABS = ['open', 'done', 'cancelled', 'archived'];

    /**
     * All tasks, finished and archived ones too: a page for finding a task, not for
     * deciding what to do. Open tasks keep the formula's order (ranked first, then the
     * ones not ready yet); finished ones go newest first.
     */
    public function all(Request $request): Response
    {
        $user = $request->user();
        AutoArchive::run($user, $this->ranking->now());
        $tab = in_array($request->query('tab'), self::ALL_TABS, true) ? $request->query('tab') : 'open';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $project = $request->query('project'); // a project's id, "inbox", or nothing
        $filter = function ($query) use ($q, $project) {
            if ($q !== '') {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('notes', 'like', $like));
            }
            if ($project === 'inbox') {
                $query->whereNull('project_id');
            } elseif (ctype_digit((string) $project)) {
                $query->where('project_id', (int) $project);
            }

            return $query;
        };
        $of = fn (string $t) => match ($t) {
            'open' => $user->tasks()->open(),
            'done' => $user->tasks()->where('status', Task::DONE)->whereNull('archived_at'),
            'cancelled' => $user->tasks()->where('status', Task::CANCELLED)->whereNull('archived_at'),
            default => $user->tasks()->whereNotNull('archived_at'),
        };
        $limit = 300;
        $total = $filter($of($tab))->count();
        $list = $this->ranking->rank($user);

        if ($tab === 'open') {
            $ids = $filter($of('open'))->pluck('id')->flip();
            $ranked = collect($list->toArray())->filter(fn ($r) => $ids->has($r['id']))->values();
            $rest = $this->ranking->notReady($user)->filter(fn ($r) => $ids->has($r['task']->id))->map(fn ($r) => TaskPresenter::notReady($r));
            $rows = $ranked->concat($rest)->take($limit)->values();
        } else {
            $order = ['done' => 'completed_at', 'cancelled' => 'cancelled_at', 'archived' => 'archived_at'][$tab];
            $rows = $filter($of($tab))->with(['project.goal', 'goal'])->latest($order)->latest('id')->limit($limit)->get()->map(fn (Task $t) => TaskPresenter::base($t))->values();
        }

        return Inertia::render('tasks/all', [
            'tab' => $tab,
            'q' => $q,
            'project' => $project === 'inbox' || ctype_digit((string) $project) ? (string) $project : null,
            'rows' => $rows,
            'total' => $total,
            'counts' => collect(self::ALL_TABS)->mapWithKeys(fn ($t) => [$t => $filter($of($t))->count()]),
            'selected' => fn () => $this->detail($request),
            'projects' => $user->projects()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Next actions: each project's best task by the formula (its first in the
     * one ranking), projects in the order of those tasks, and the projects with
     * nothing ready, with why.
     */
    public function next(Request $request, NextActions $next): Response
    {
        return Inertia::render('tasks/next', $next->for($request->user()));
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
        $task = Task::with(['project.goal', 'goal', 'parent:id,title', 'steps', 'children', 'dependencies', 'dependents', 'files', 'logs.files', 'linkedNotes.event', 'people', 'githubLinks', 'reminders', 'events' => fn ($q) => $q->limit(8)])
            ->where('user_id', $user->id)->find($id);
        if (! $task) {
            return null;
        }
        $list = $this->ranking->rank($user);
        $k = $list->rankOf($task->id);
        $base = $k ? $list->present($k - 1) : TaskPresenter::base($task);

        return $base + [
            'total' => count($list),
            'children' => $task->children->map(fn ($c) => [
                'id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'dueAt' => $c->due_at?->toIso8601ZuluString(),
                'rank' => $list->rankOf($c->id), 'score' => $list->scoreOf($c->id),
            ])->values(),
            'dependsOn' => $task->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values(),
            // Tasks that wait on this one.
            'blocks' => $task->dependents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values(),
            // Open tasks this one could be set to start after.
            // The task's own files; files on a log entry show with the entry.
            'files' => $task->files->whereNull('log_id')->map(fn ($f) => $f->present())->values(),
            'logs' => $task->logs->map(fn ($l) => $l->present())->values(),
            'reminders' => $task->reminders->map(fn ($r) => $r->present())->values(),
            // A task made from a note's words keeps them ("quote"), to jump back to that place.
            'linkedNotes' => $task->linkedNotes->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle(), 'quote' => $n->pivot->quote])->values(),
            'people' => $task->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'peopleChoices' => $user->people()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'focused' => ['seconds' => (int) $task->focusSessions()->whereNotNull('ended_at')->sum('seconds'), 'sessions' => $task->focusSessions()->whereNotNull('ended_at')->count()],
            'candidates' => $user->tasks()->open()->whereKeyNot($task->id)->orderBy('title')->limit(300)->get(['id', 'title'])->values(),
            'events' => $task->events->map(fn ($e) => ['type' => $e->type, 'at' => $e->created_at?->toIso8601ZuluString(), 'via' => $e->via])->values(),
            // Its history in plain words, newest first.
            'activity' => TaskPresenter::activity($task, $user),
            // Linked GitHub issues and pull requests (as last read; the panel asks for a fresh look when they are old).
            'github' => $user->github_login || $task->githubLinks->isNotEmpty() ? [
                'connected' => $user->github_login !== null,
                'repo' => $task->project?->github_repo,
                'links' => $task->githubLinks->map(fn (GithubLink $l) => $l->present())->values(),
                'stale' => $task->githubLinks->contains(fn (GithubLink $l) => ! $l->fetched_at || $l->fetched_at->lt(now()->subMinutes(GitHub::STALE_MINUTES))),
            ] : null,
        ];
    }

    /** Quick add (plain words in) or the FYP task form (one field per attribute, FR2). */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $request->has('text')) {
            $task = $this->actions->create($user, $this->formFields($request));

            return back()->with('toast', $this->placed($task, 'Added'))->with('undo', $this->undo('delete-task', $task->id));
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'importance' => ['nullable', 'integer', 'between:1,5'],
            'urgency' => ['nullable', 'integer', 'between:1,5'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'plain' => ['nullable', 'array', 'max:40'],
            'plain.*' => ['string', 'max:80'],
            'capture' => ['nullable', 'boolean'],
        ]);
        $capture = (bool) ($data['capture'] ?? false);
        $task = $this->actions->quickAdd($user, $data['text'], $data['importance'] ?? null, $data['urgency'] ?? null, $data['project_id'] ?? null, $data['plain'] ?? [], $capture);
        if ($capture) {
            return back()->with('toast', "Captured “{$task->title}” · in the Inbox to sort")->with('undo', $this->undo('delete-task', $task->id));
        }
        $rank = $this->ranking->rank($user)->rankOf($task->id);
        $later = ! $rank && $task->available_from && $task->available_from > $this->ranking->now() ? ' · starts '.$task->available_from->timezone($user->timezone)->format('D j M') : '';

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : $later))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** "Rank it": a captured task joins the ranking (its place comes from the formula, like any task). */
    public function rankIt(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $task->update(['captured_at' => null]);
        $rank = $this->ranking->rank($user)->rankOf($task->id);

        return back()->with('toast', "“{$task->title}” is ranked".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('capture-task', $task->id));
    }

    /** Undo of "Rank it": back to the Inbox's "To sort". */
    public function capture(Request $request, Task $task): RedirectResponse
    {
        $this->own($task, $request->user());
        $task->update(['captured_at' => $this->ranking->now()]);

        return back()->with('toast', "“{$task->title}” is back in To sort");
    }

    /** Where a task will land, before it is saved: from quick-add words or from the FYP form's fields. */
    public function preview(Request $request): JsonResponse
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

            return response()->json(['preview' => $this->actions->place($input, $user, $data['except'] ?? null)]);
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'], 'importance' => ['integer', 'between:1,5'], 'urgency' => ['integer', 'between:1,5'],
            'plain' => ['nullable', 'array', 'max:40'], 'plain.*' => ['string', 'max:80'],
        ]);
        $parsed = $this->actions->parse($user, $data['text'], $data['plain'] ?? []);
        if ($parsed['title'] === '') {
            return response()->json(['parsed' => ['picked' => $parsed['picked']], 'preview' => null]);
        }
        $input = new TaskInput(0, $parsed['title'], (int) ($data['importance'] ?? $parsed['importance'] ?? 3), (int) ($data['urgency'] ?? 3), $parsed['due']?->toDateTimeImmutable(), $parsed['hours'] ?? 1.0, $now);
        $remind = $parsed['remind'];

        return response()->json([
            'parsed' => [
                'title' => $parsed['title'],
                'due' => $parsed['due']?->utc()->toIso8601ZuluString(),
                'hours' => $parsed['hours'],
                'project' => $parsed['projectId'] ? $user->projects()->find($parsed['projectId'])?->only('id', 'name') : null,
                'repeat' => $parsed['repeat'],
                'start' => $parsed['start']?->utc()->toIso8601ZuluString(),
                'remind' => $remind ? ($remind['kind'] === 'at' ? ['kind' => 'at', 'at' => $remind['at']->utc()->toIso8601ZuluString()] : $remind) : null,
                'importance' => $parsed['importance'],
                'energy' => $parsed['energy'] ?? null,
                'picked' => $parsed['picked'],
            ],
            // A task that starts later isn't ranked until then.
            'preview' => $parsed['start'] && $parsed['start']->greaterThan($this->ranking->now()) ? null : $this->actions->place($input, $user),
        ]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if ($request->boolean('form')) {
            $data = $this->formFields($request);
        } else {
            $data = $request->validate(TaskActions::updateRules($user), [], ['depends_on.*' => 'task', 'available_from' => 'start date']);
            foreach (['due_at', 'available_from', 'reminder_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = $this->fromLocal($data[$key], $user);
                }
            }
        }
        $event = $this->actions->update($task, $user, $data);
        if (! $event) {
            return back();
        }
        $deps = $event->snapshot['depends_on'] ?? null;
        $changed = array_keys(array_diff_key($event->snapshot['after'] ?? [], ['repeat_day' => 1, 'repeat_every' => 1, 'repeat_anchor' => 1, 'reminder_sent_at' => 1, 'waiting_since' => 1]));
        $what = $deps ? 'Starts after' : (self::LABELS[$changed[0] ?? ''] ?? 'Task');
        $toast = $request->boolean('form') ? $this->placed($task, 'Saved') : "{$what} changed";
        // A status change says what it became: "Doing: “Essay”".
        if (! $request->boolean('form') && ! $deps && ($changed[0] ?? null) === 'status') {
            $toast = ['todo' => 'To do', 'doing' => 'Doing', 'waiting' => 'Waiting'][$task->status] ?? 'Status changed';
            $toast .= $toast === 'Status changed' ? '' : ": “{$task->title}”";
        }

        return back()->with('toast', $toast)->with('undo', $this->undo('revert-task', $task->id, ['event' => $event->id]));
    }

    private const LABELS = [
        'title' => 'Title', 'notes' => 'Notes', 'importance' => 'Importance', 'urgency' => 'Urgency', 'energy' => 'Energy', 'due_at' => 'Due date',
        'est_hours' => 'Work', 'status' => 'Status', 'waiting_for' => 'Waiting for', 'follow_up_on' => 'Follow-up', 'project_id' => 'Project', 'goal_id' => 'Goal',
        'repeat_unit' => 'Repeat', 'repeat_days' => 'Repeat', 'repeat_until' => 'Repeat', 'available_from' => 'Start date', 'reminder_at' => 'Reminder',
    ];

    /** Undo of one change in the task panel: put back what the saved event remembers. */
    public function revert(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $event = $task->events()->where('type', 'updated')->findOrFail($request->integer('event'));
        $snap = $event->snapshot;
        if (! empty($snap['depends_on'])) {
            $this->actions->setDependencies($task, $snap['depends_on']['before']);
        }
        if (! empty($snap['before'])) {
            $task->update($snap['before']);
        }
        $task->events()->create(['user_id' => $user->id, 'type' => 'reverted', 'snapshot' => ['event' => $event->id]]);
        // Taken back: Review no longer counts it (a due date moved later is not "postponed").
        $event->update(['snapshot' => [...$snap, 'undone' => true]]);

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
        // Still waiting on another task: done only when asked for ("Complete anyway" in the panel).
        $waiting = $user->fyp_mode ? null : TaskLinks::waitingOn($task);
        if ($waiting && ! $request->boolean('anyway')) {
            return back()->with('toast', "“{$task->title}” is still waiting on “{$waiting->title}”. Open it to complete it anyway.")
                ->with('toastOpen', route('tasks.index', ['task' => $task->id], false));
        }
        // Open subtasks: complete them too, or only this one (the panel asks).
        $mode = $request->validate(['children' => ['sometimes', Rule::in(['complete', 'leave'])]])['children'] ?? null;
        $kids = $user->fyp_mode ? collect() : $task->children()->open()->get();
        if ($kids->isNotEmpty() && ! $mode) {
            $n = $kids->count();

            return back()->with('toast', "“{$task->title}” has {$n} open ".($n === 1 ? 'subtask' : 'subtasks').'. Open it to choose.')
                ->with('toastOpen', route('tasks.index', ['task' => $task->id], false));
        }
        $list = $mode === 'complete' && $kids->isNotEmpty() ? $this->ranking->rank($user) : null;
        $next = $this->actions->complete($task, $user);
        if ($request->boolean('undo')) {
            $this->actions->undid($task, 'completed', ['reopened']);
        }
        if ($list) {
            $kids->each(fn (Task $kid) => $this->actions->complete($kid, $user, $list->scoreOf($kid->id), false));
            $n = $kids->count();

            return back()->with('toast', "Done: “{$task->title}” and {$n} ".($n === 1 ? 'subtask' : 'subtasks').$this->freedWords($task, $user))
                ->with('undo', $this->undo('bulk-undo', 0, ['action' => 'complete', 'ids' => [$task->id, ...$kids->pluck('id')->all()], 'before' => []]));
        }
        $toast = "Done: “{$task->title}”".($next ? ' · next one '.$next->due_at->timezone($user->timezone)->format('D j M') : '').$this->freedWords($task, $user);

        return back()->with('toast', $toast)->with('undo', $this->undo('reopen', $task->id, ['undo' => true]));
    }

    /** "Add subtask": a small task under this one, ranked like any other. It starts with the parent's importance, urgency and due date. */
    public function subtask(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $title = trim($request->validate(['title' => ['required', 'string', 'max:255']])['title']);
        $kid = $this->actions->create($user, [
            'title' => $title, 'parent_id' => $task->id, 'project_id' => $task->project_id, 'goal_id' => $task->project_id ? null : $task->goal_id,
            'importance' => $task->importance, 'urgency' => $task->urgency, 'due_at' => $task->due_at, 'est_hours' => 1,
        ]);

        return back()->with('toast', "Added subtask “{$kid->title}”")->with('undo', $this->undo('delete-task', $kid->id));
    }

    /** " · “Slides” can start now": tasks that waited on this one and now can (personal mode). */
    private function freedWords(Task $task, User $user): string
    {
        return $user->fyp_mode ? '' : TaskLinks::words(TaskLinks::freed($task, $this->ranking->now()), 'can start now', 'can start now');
    }

    /** "Blocks": a task that waits on this one, added or taken away (it is set to start after this one). */
    public function blocks(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $data = $request->validate([
            'add' => ['required_without:remove', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'remove' => ['required_without:add', 'integer'],
        ]);
        $other = Task::where('user_id', $user->id)->findOrFail($data['add'] ?? $data['remove']);
        $ids = $other->dependencies()->pluck('tasks.id')->all();
        $after = isset($data['add']) ? array_values(array_unique([...$ids, $task->id])) : array_values(array_diff($ids, [$task->id]));
        $event = $this->actions->update($other, $user, ['depends_on' => $after]);

        return back()->with('toast', isset($data['add']) ? "“{$other->title}” now starts after this one" : "“{$other->title}” doesn’t wait on this one any more")
            ->with('undo', $event ? $this->undo('revert-task', $other->id, ['event' => $event->id]) : null);
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
            'action' => ['required', Rule::in(self::BULK)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'due_at' => ['nullable', 'date'],
            'importance' => ['required_if:action,importance', 'integer', 'between:1,5'],
            'urgency' => ['required_if:action,urgency', 'integer', 'between:1,5'],
            'status' => ['required_if:action,status', Rule::in(Task::OPEN)],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // Only the user's own tasks that the action fits (open ones to finish, finished ones to reopen …); the rest are quietly left out.
        $query = $user->tasks()->whereIn('id', $data['ids']);
        $tasks = (match ($data['action']) {
            'reopen' => $query->whereIn('status', Task::FINISHED),
            'archive' => $query->whereIn('status', Task::FINISHED)->whereNull('archived_at'),
            'unarchive' => $query->whereNotNull('archived_at'),
            'delete', 'move' => $query,
            default => $query->open(),
        })->get();
        if ($tasks->isEmpty()) {
            return back();
        }
        $n = $tasks->count();
        $noun = $n === 1 ? 'task' : 'tasks';
        $before = [];

        switch ($data['action']) {
            case 'complete':
                $list = $this->ranking->rank($user); // one ranking: each task keeps the score it had at this moment
                $tasks->each(fn (Task $t) => $this->actions->complete($t, $user, $list->scoreOf($t->id), false));
                $toast = "{$n} {$noun} done";
                break;
            case 'cancel':
                $tasks->each(fn (Task $t) => $this->actions->cancel($t, $user));
                $toast = "Cancelled {$n} {$noun}";
                break;
            case 'delete':
                $tasks->each(fn (Task $t) => $this->actions->delete($t, $user));
                $toast = "Deleted {$n} {$noun}";
                break;
            case 'reopen':
                foreach ($tasks as $t) {
                    $before[$t->id] = ['status' => $t->status, 'score' => $t->score_at_completion, 'reason' => $t->cancel_reason, 'archived' => $t->archived_at !== null];
                    $this->actions->reopen($t, $user);
                }
                $toast = "Opened {$n} {$noun} again";
                break;
            case 'archive':
                $tasks->each(fn (Task $t) => $this->actions->archive($t, $user));
                $toast = "Archived {$n} {$noun}";
                break;
            case 'unarchive':
                $tasks->each(fn (Task $t) => $this->actions->unarchive($t, $user));
                $toast = "Took {$n} {$noun} out of the archive";
                break;
            case 'move':
                $projectId = $data['project_id'] ?? null;
                foreach ($tasks as $t) {
                    $before[$t->id] = ['project_id' => $t->project_id];
                    $t->update(['project_id' => $projectId]);
                    $this->history($t, $user, $before[$t->id], ['project_id' => $projectId]);
                }
                $toast = "Moved {$n} {$noun}";
                break;
            case 'importance':
            case 'urgency':
            case 'status':
            case 'follow_up':
                // Through the same change as the panel's (history, "waiting since"); Undo puts each task's old value back.
                $field = $data['action'] === 'follow_up' ? 'follow_up_on' : $data['action'];
                $value = $data[$field] ?? null;
                foreach ($tasks as $t) {
                    $before[$t->id] = [$field => $field === 'follow_up_on' ? $t->follow_up_on?->toDateString() : $t->{$field}]
                        + ($field === 'status' ? ['waiting_since' => $t->waiting_since?->toIso8601ZuluString()] : []);
                    $this->actions->update($t, $user, [$field => $value]);
                }
                $toast = match ($field) {
                    'status' => 'Set '.$n.' '.$noun.' to '.['todo' => 'To do', 'doing' => 'Doing', 'waiting' => 'Waiting'][$value],
                    'follow_up_on' => $value ? "Follow-up set on {$n} {$noun}" : "Follow-up cleared on {$n} {$noun}",
                    default => ucfirst($field)." set on {$n} {$noun}",
                };
                break;
            default: // due
                $due = $this->fromLocal($data['due_at'] ?? null, $user);
                foreach ($tasks as $t) {
                    $before[$t->id] = ['due_at' => $t->due_at?->toIso8601ZuluString(), 'repeat_day' => $t->repeat_day];
                    $t->update(['due_at' => $due, 'repeat_day' => null]);
                    $this->history($t, $user, ['due_at' => $before[$t->id]['due_at']], ['due_at' => $due?->toIso8601ZuluString()]);
                }
                $toast = $due ? "Due date set on {$n} {$noun}" : "Due date cleared on {$n} {$noun}";
        }

        return back()->with('toast', $toast)
            ->with('undo', $this->undo('bulk-undo', 0, ['action' => $data['action'], 'ids' => $tasks->pluck('id')->all(), 'before' => $before]));
    }

    /** What the selection bar can do to several tasks at once. */
    private const BULK = ['complete', 'cancel', 'delete', 'move', 'due', 'reopen', 'archive', 'unarchive', 'importance', 'urgency', 'status', 'follow_up'];

    /** A change from the selection bar goes into the task's history like one from its panel (Review counts it). */
    private function history(Task $task, User $user, array $before, array $after): void
    {
        $task->events()->create(['user_id' => $user->id, 'type' => 'updated', 'snapshot' => ['before' => $before, 'after' => $after, 'depends_on' => null]]);
    }

    /** Undo of a bulk action: not done again, restored, or back to each task's old project / due date. */
    public function bulkUndo(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(self::BULK)],
            'before' => ['nullable', 'array'],
            'before.*.status' => ['sometimes', Rule::in(Task::STATUSES)],
            'before.*.score' => ['nullable', 'numeric'],
            'before.*.reason' => ['nullable', 'string', 'max:300'],
            'before.*.archived' => ['sometimes', 'boolean'],
            'before.*.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'before.*.due_at' => ['nullable', 'date'],
            'before.*.repeat_day' => ['nullable', 'integer', 'between:1,31'],
            'before.*.importance' => ['sometimes', 'integer', 'between:1,5'],
            'before.*.urgency' => ['sometimes', 'integer', 'between:1,5'],
            'before.*.follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'before.*.waiting_since' => ['nullable', 'date'],
        ]);
        $tasks = Task::withTrashed()->where('user_id', $user->id)->whereIn('id', $data['ids'])->get();

        foreach ($tasks as $t) {
            if (($data['action'] === 'complete' && $t->status === Task::DONE) || ($data['action'] === 'cancel' && $t->status === Task::CANCELLED)) {
                $this->actions->reopen($t, $user);
                $this->actions->undid($t, 'reopened', ['completed', 'cancelled']);
            } elseif ($data['action'] === 'delete' && $t->trashed()) {
                $this->actions->restore($t, $user);
            } elseif ($data['action'] === 'reopen' && $t->isOpen() && isset($data['before'][$t->id]['status'])) {
                // Finished again as it was: done with its old score, or cancelled with its reason; archived if it was.
                $was = $data['before'][$t->id];
                $was['status'] === Task::DONE
                    ? $this->actions->complete($t, $user, isset($was['score']) ? (float) $was['score'] : null, false)
                    : $this->actions->cancel($t, $user, $was['reason'] ?? null);
                $this->actions->undid($t, $was['status'] === Task::DONE ? 'completed' : 'cancelled', ['reopened']);
                if (! empty($was['archived'])) {
                    $this->actions->archive($t, $user);
                }
            } elseif ($data['action'] === 'archive' && $t->archived_at) {
                $this->actions->unarchive($t, $user);
            } elseif ($data['action'] === 'unarchive' && ! $t->archived_at && in_array($t->status, Task::FINISHED, true)) {
                $this->actions->archive($t, $user);
            } elseif (in_array($data['action'], ['move', 'due', 'importance', 'urgency', 'follow_up'], true) && isset($data['before'][$t->id])) {
                $t->update(array_intersect_key($data['before'][$t->id], ['project_id' => 1, 'due_at' => 1, 'repeat_day' => 1, 'importance' => 1, 'urgency' => 1, 'follow_up_on' => 1]));
                // The due date it was moved to is taken back: Review no longer counts it as postponed.
                $moved = $data['action'] === 'due' ? $t->events()->where('type', 'updated')->first() : null;
                if ($moved && array_key_exists('due_at', $moved->snapshot['after'] ?? [])) {
                    $moved->update(['snapshot' => [...$moved->snapshot, 'undone' => true]]);
                }
            } elseif ($data['action'] === 'status' && $t->isOpen() && in_array($data['before'][$t->id]['status'] ?? null, Task::OPEN, true)) {
                $t->update(['status' => $data['before'][$t->id]['status'], 'waiting_since' => $data['before'][$t->id]['waiting_since'] ?? null]);
            }
        }

        return back()->with('toast', 'Undone');
    }

    public function reopen(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if (! in_array($task->status, Task::FINISHED, true)) {
            return back();
        }
        $status = $request->validate(['status' => ['sometimes', Rule::in(Task::OPEN)]])['status'] ?? null;
        // From Undo (of Done or Cancel) it is marked, so Review doesn't count it as "opened again".
        $fromUndo = $request->boolean('undo');
        if ($task->status === Task::CANCELLED) {
            // Undo cancels it again the same way (skipping a repeat makes the next copy again).
            $undo = $this->undo('cancel-task', $task->id, ['reason' => $task->cancel_reason, 'skip' => $task->nextRepeat()->open()->exists(), 'undo' => true]);
            $this->actions->reopen($task, $user, $status);
            if ($fromUndo) {
                $this->actions->undid($task, 'reopened', ['cancelled']);
            }

            return back()->with('toast', "Back: “{$task->title}”".$this->againWords($task, $user))->with('undo', $undo);
        }
        $this->actions->reopen($task, $user, $status);
        if ($fromUndo) {
            $this->actions->undid($task, 'reopened', ['completed']);
        }

        // Undo completes it again, even if it is still waiting on something.
        return back()->with('toast', "Not done: “{$task->title}”".$this->againWords($task, $user))->with('undo', $this->undo('complete', $task->id, ['anyway' => true, 'undo' => true]));
    }

    /** " · 2 tasks wait on it again": opened again, the tasks after it can't start (personal mode). */
    private function againWords(Task $task, User $user): string
    {
        return $user->fyp_mode ? '' : TaskLinks::words(TaskLinks::waitingAgain($task), 'waits on it again', 'wait on it again');
    }

    /** "Cancel task": it won't be done (with a reason, if given). A repeating one can skip only this time. */
    public function cancel(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
            'skip' => ['sometimes', 'boolean'],
            // Its open subtasks: cancelled with it, or left in the list (the panel asks, as Done and Delete do).
            'children' => ['sometimes', Rule::in(['cancel', 'leave'])],
        ]);
        if (! in_array($task->status, Task::OPEN, true)) {
            return back();
        }
        $skip = ($data['skip'] ?? false) && $task->repeat_unit;
        $reason = trim($data['reason'] ?? '') ?: ($skip ? 'Skipped' : null);
        $kids = ($data['children'] ?? null) === 'cancel' && ! $user->fyp_mode ? $task->children()->open()->get() : collect();
        $next = $this->actions->cancel($task, $user, $reason, (bool) $skip);
        if ($request->boolean('undo')) {
            $this->actions->undid($task, 'cancelled', ['reopened']);
        }
        $kids->each(fn (Task $kid) => $this->actions->cancel($kid, $user, $reason));
        $n = $kids->count();
        $and = $n ? " and {$n} ".($n === 1 ? 'subtask' : 'subtasks') : '';
        $toast = $skip
            ? "Skipped “{$task->title}”{$and}".($next?->due_at ? ' · next one '.$next->due_at->timezone($user->timezone)->format('D j M') : '')
            : "Cancelled “{$task->title}”{$and}".$this->freedWords($task, $user);

        return back()->with('toast', $toast)->with('undo', $n
            ? $this->undo('bulk-undo', 0, ['action' => 'cancel', 'ids' => [$task->id, ...$kids->pluck('id')->all()], 'before' => []])
            : $this->undo('reopen', $task->id, ['undo' => true]));
    }

    /** A done or cancelled task goes to the archive (out of project pages, Done today and search). */
    public function archive(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if (! in_array($task->status, Task::FINISHED, true) || $task->archived_at) {
            return back();
        }
        $this->actions->archive($task, $user);

        return back()->with('toast', "Archived “{$task->title}”")->with('undo', $this->undo('unarchive-task', $task->id));
    }

    public function unarchive(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if (! $task->archived_at) {
            return back();
        }
        $this->actions->unarchive($task, $user);

        return back()->with('toast', "“{$task->title}” is out of the archive")->with('undo', $this->undo('archive-task', $task->id));
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        // A parent task: its subtasks go too, or stay as tasks of their own (the panel asks; keeping is the default).
        $withKids = $request->validate(['children' => ['sometimes', Rule::in(['delete', 'keep'])]])['children'] ?? 'keep';
        $n = $withKids === 'delete' ? $task->children()->count() : 0;
        $this->actions->delete($task, $user, $withKids === 'delete');

        return redirect()->to(url()->previous() === url()->current() ? route('tasks.index') : preg_replace('/([?&])task=\d+&?/', '$1', url()->previous()))
            ->with('toast', "Deleted “{$task->title}”".($n ? " and its {$n} ".($n === 1 ? 'subtask' : 'subtasks') : ''))
            ->with('undo', ['action' => 'restore', 'id' => $task->id]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $task = Task::withTrashed()->where('user_id', $user->id)->findOrFail($id);
        $this->actions->restore($task, $user);

        return back()->with('toast', "Restored “{$task->title}”")->with('undo', $this->undo('delete-task', $task->id));
    }
}
