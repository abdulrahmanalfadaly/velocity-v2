<?php

namespace App\Http\Controllers;

use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\TaskInput;
use App\Models\Task;
use App\Models\User;
use App\Services\QuickAddParser;
use App\Services\RankingService;
use App\Services\StudyLogger;
use App\Services\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /** Full details for the task panel (?task=id), including its place in the ranking. */
    private function detail(Request $request): ?array
    {
        $id = (int) $request->query('task');
        if (! $id) {
            return null;
        }
        $user = $request->user();
        $task = Task::with(['project.goal', 'parent:id,title', 'steps', 'children', 'dependencies', 'events' => fn ($q) => $q->limit(8)])
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

            return back()->with('toast', $this->placed($task, 'Added'));
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:300'],
            'importance' => ['nullable', 'integer', 'between:1,5'],
            'urgency' => ['nullable', 'integer', 'between:1,5'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ]);
        $parsed = $parser->parse($data['text'], $this->ranking->now()->timezone($user->timezone), $user->projects()->pluck('name', 'id')->all());
        abort_if($parsed['title'] === '', 422, 'A task needs a title.');

        $task = $user->tasks()->create([
            'title' => $parsed['title'],
            'importance' => $data['importance'] ?? 3,
            'urgency' => $data['urgency'] ?? 3,
            'due_at' => $parsed['due']?->utc(),
            'est_hours' => $parsed['hours'] ?? 1,
            'project_id' => $data['project_id'] ?? $parsed['projectId'],
        ]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created']);
        $rank = $this->ranking->rank($user)->rankOf($task->id);

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : ''));
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

        $data = $request->validate(['text' => ['required', 'string', 'max:300'], 'importance' => ['integer', 'between:1,5'], 'urgency' => ['integer', 'between:1,5']]);
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
            ]);
            if (array_key_exists('due_at', $data)) {
                $data['due_at'] = $this->fromLocal($data['due_at'], $user);
            }
        }
        $before = $task->only(array_keys($data));
        $task->update($data);
        $task->events()->create(['user_id' => $user->id, 'type' => 'updated', 'snapshot' => ['before' => $before, 'after' => $task->only(array_keys($data))]]);

        return $request->boolean('form') ? back()->with('toast', $this->placed($task, 'Saved')) : back();
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
        $score = $this->ranking->rank($user)->scoreOf($task->id);
        $task->update(['status' => Task::DONE, 'completed_at' => $this->ranking->now(), 'score_at_completion' => $score]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'completed', 'snapshot' => ['score' => $score]]);

        return back()->with('toast', "Done: “{$task->title}”")->with('undo', ['action' => 'reopen', 'id' => $task->id]);
    }

    public function reopen(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $task->update(['status' => Task::TODO, 'completed_at' => null, 'score_at_completion' => null]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'reopened']);

        return back();
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

        return back()->with('toast', "Restored “{$task->title}”");
    }
}
