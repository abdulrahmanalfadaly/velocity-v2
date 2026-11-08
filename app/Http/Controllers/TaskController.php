<?php

namespace App\Http\Controllers;

use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\TaskInput;
use App\Models\Task;
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
    public function __construct(private readonly RankingService $ranking)
    {
    }

    /** Up next: every task you can work on, in priority order. */
    public function index(Request $request, StudyLogger $log): Response
    {
        $user = $request->user();
        $list = $this->ranking->rank($user);
        $log->ranking($user, $list, 'tasks');
        $now = $this->ranking->now()->timezone($user->timezone);

        return Inertia::render('tasks/index', [
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

    /** Quick add: plain words in, a ranked task out. */
    public function store(Request $request, QuickAddParser $parser): RedirectResponse
    {
        $user = $request->user();
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

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank} in your list" : ''));
    }

    /** What quick add will understand, and where the task will land – before you save. */
    public function preview(Request $request, QuickAddParser $parser, PrioritizationEngine $engine): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['text' => ['required', 'string', 'max:300'], 'importance' => ['integer', 'between:1,5'], 'urgency' => ['integer', 'between:1,5']]);
        $nowLocal = $this->ranking->now()->timezone($user->timezone);
        $parsed = $parser->parse($data['text'], $nowLocal, $user->projects()->pluck('name', 'id')->all());
        if ($parsed['title'] === '') {
            return response()->json(['parsed' => $parsed, 'preview' => null]);
        }
        $list = $this->ranking->rank($user);
        $scored = $engine->score(
            new TaskInput(0, $parsed['title'], (int) ($data['importance'] ?? 3), (int) ($data['urgency'] ?? 3), $parsed['due']?->toDateTimeImmutable(), $parsed['hours'] ?? 1.0, $this->ranking->now()->toDateTimeImmutable()),
            $this->ranking->now()->toDateTimeImmutable(),
            $list->params,
        );
        $rank = 1 + count(array_filter($list->scored, fn ($s) => PrioritizationEngine::compare($s, $scored) < 0));
        $above = $rank > 1 ? $list->tasks[(int) $list->scored[$rank - 2]->task->id]->title : null;

        return response()->json([
            'parsed' => [
                'title' => $parsed['title'],
                'due' => $parsed['due']?->utc()->toIso8601ZuluString(),
                'hours' => $parsed['hours'],
                'project' => $parsed['projectId'] ? $user->projects()->find($parsed['projectId'])?->only('id', 'name') : null,
                'picked' => $parsed['picked'],
            ],
            'preview' => [
                'rank' => $rank,
                'total' => count($list) + 1,
                'score' => $scored->displayScore(),
                'contrib' => array_map(fn ($v) => Rounding::halfUp($v, 1), $scored->contributions),
                'above' => $above,
            ],
        ]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
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
        $before = $task->only(array_keys($data));
        $task->update($data);
        $task->events()->create(['user_id' => $user->id, 'type' => 'updated', 'snapshot' => ['before' => $before, 'after' => $task->only(array_keys($data))]]);

        return back();
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
