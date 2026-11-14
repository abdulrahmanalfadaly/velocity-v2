<?php

namespace App\Http\Controllers\Api;

use App\Actions\FocusActions;
use App\Actions\TaskActions;
use App\Domain\Prioritization\TaskInput;
use App\Http\Controllers\FocusController;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use App\Support\ApiPresent;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Tasks, their steps and the focus timer. */
class TaskApiController extends ApiController
{
    public function __construct(RankingService $ranking, private readonly TaskActions $actions, private readonly FocusActions $focus)
    {
        parent::__construct($ranking);
    }

    /**
     * view=upnext (the ranking, default) | inbox (ranked, no project) | waiting (open
     * but not ready) | done (newest first). Optional project_id, q and limit.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = $request->validate([
            'view' => ['nullable', Rule::in(['upnext', 'inbox', 'waiting', 'done'])],
            'project_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $view = $q['view'] ?? 'upnext';
        $limit = $q['limit'] ?? 50;
        $list = $this->ranking->rank($user);
        $keep = fn (Task $t) => (! isset($q['project_id']) || (int) $t->project_id === (int) $q['project_id'])
            && (! isset($q['q']) || mb_stripos($t->title, $q['q']) !== false);

        $rows = match ($view) {
            'upnext', 'inbox' => collect($list->scored)->map(fn ($s) => $list->tasks[(int) $s->task->id])
                ->filter(fn (Task $t) => $keep($t) && ($view === 'upnext' || $t->project_id === null))
                ->map(fn (Task $t) => ApiPresent::task($t, $list)),
            'waiting' => $this->ranking->notReady($user)->filter(fn ($r) => $keep($r['task']))
                ->map(fn ($r) => ApiPresent::task($r['task']) + ['kind' => $r['kind'], 'why' => $r['why']]),
            default => $user->tasks()->where('status', Task::DONE)->with('project.goal')->latest('completed_at')->limit(500)->get()
                ->filter($keep)->map(fn (Task $t) => ApiPresent::task($t)),
        };

        return $this->ok($rows->take($limit)->values(), meta: ['view' => $view, 'count' => $rows->count(), 'ranked' => count($list)]);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $task->load(['project.goal', 'parent:id,title', 'steps', 'dependencies', 'linkedNotes.event', 'people', 'events' => fn ($q) => $q->limit(20)]);

        return $this->ok(ApiPresent::task($task, $this->ranking->rank($user)) + [
            'dependsOn' => $task->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values(),
            'notes' => $task->linkedNotes->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle()])->values(),
            'people' => $task->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'focusedMinutes' => (int) round($task->focusSessions()->whereNotNull('ended_at')->sum('seconds') / 60),
            'history' => $task->events->map(fn ($e) => ['type' => $e->type, 'at' => $e->created_at?->toIso8601ZuluString(), 'via' => $e->via])->values(),
        ]);
    }

    /** {"text": "Ethics essay outline fri 3h #fyp"} like quick add, or the fields one by one ({"title": …}). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($request->has('text')) {
            $d = $request->validate([
                'text' => ['required', 'string', 'max:255'],
                'importance' => ['nullable', 'integer', 'between:1,5'],
                'urgency' => ['nullable', 'integer', 'between:1,5'],
                'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            ]);
            $task = $this->actions->quickAdd($user, $d['text'], $d['importance'] ?? null, $d['urgency'] ?? null, $d['project_id'] ?? null);
        } else {
            $rules = TaskActions::updateRules($user);
            $rules['title'] = ['required', 'string', 'max:255'];
            $d = $this->dates($request->validate($rules), $user);
            $deps = Arr::pull($d, 'depends_on');
            $task = $this->actions->create($user, $d + ['importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
            if ($deps) {
                $this->actions->setDependencies($task, $deps);
            }
        }

        return $this->ok(ApiPresent::task($task->fresh(['project.goal']), $this->ranking->rank($user)), 201);
    }

    /** Where a task would land, without saving it: {"text": "…"} or importance, urgency, due_at, est_hours. */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'text' => ['required_without:est_hours', 'nullable', 'string', 'max:255'],
            'importance' => ['nullable', 'integer', 'between:1,5'],
            'urgency' => ['nullable', 'integer', 'between:1,5'],
            'due_at' => ['nullable', 'date'],
            'est_hours' => ['nullable', 'numeric', 'min:0.05', 'max:500'],
        ]);
        $title = 'preview';
        $due = $this->at($d['due_at'] ?? null, $user, true);
        $hours = (float) ($d['est_hours'] ?? 1);
        if (isset($d['text'])) {
            $p = $this->actions->parse($user, $d['text']);
            [$title, $due, $hours] = [$p['title'] ?: 'preview', $p['due']?->utc() ?? $due, (float) ($p['hours'] ?? $hours)];
        }
        $input = new TaskInput(0, $title, (int) ($d['importance'] ?? 3), (int) ($d['urgency'] ?? 3), $due?->toDateTimeImmutable(), $hours, $this->ranking->now()->toDateTimeImmutable());
        $place = $this->actions->place($input, $user);

        return $this->ok([
            'title' => $title,
            'dueAt' => $due?->toIso8601ZuluString(),
            'estHours' => $hours,
            'rank' => $place['rank'],
            'of' => $place['total'],
            'score' => $place['score'],
            'below' => $place['above'],
        ]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $this->actions->update($task, $user, $this->dates($request->validate(TaskActions::updateRules($user)), $user));

        return $this->ok(ApiPresent::task($task->fresh(['project.goal', 'steps']), $this->ranking->rank($user)));
    }

    /** Done (with the score it had). A repeating task's next copy comes back in meta.next. */
    public function complete(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $next = $task->status === Task::DONE ? null : $this->actions->complete($task, $user);
        $list = $this->ranking->rank($user);

        return $this->ok(ApiPresent::task($task->fresh(['project.goal'])), meta: ['next' => $next ? ApiPresent::task($next, $list) : null]);
    }

    public function reopen(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $status = $request->validate(['status' => ['sometimes', Rule::in(Task::OPEN)]])['status'] ?? null;
        if ($task->status === Task::DONE) {
            $this->actions->reopen($task, $user, $status);
        }

        return $this->ok(ApiPresent::task($task->fresh(['project.goal']), $this->ranking->rank($user)));
    }

    /** Deleted tasks can be brought back with POST /tasks/{id}/restore. */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $this->actions->delete($task, $user);

        return $this->ok(['id' => $task->id, 'deleted' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::onlyTrashed()->where('user_id', $user->id)->findOrFail($id);
        $this->actions->restore($task, $user);

        return $this->ok(ApiPresent::task($task->fresh(['project.goal']), $this->ranking->rank($user)));
    }

    public function addStep(Request $request, Task $task): JsonResponse
    {
        $this->own($task, $request->user());
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $step = $task->steps()->create($data + ['position' => (int) $task->steps()->max('position') + 1]);

        return $this->ok(['id' => $step->id, 'title' => $step->title, 'done' => false], 201);
    }

    public function updateStep(Request $request, TaskStep $step): JsonResponse
    {
        abort_unless($step->task && (int) $step->task->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'done' => ['sometimes', 'boolean']]);
        if (array_key_exists('done', $data)) {
            $data['done_at'] = $data['done'] ? app(Clock::class)->now() : null;
            unset($data['done']);
        }
        $step->update($data);

        return $this->ok(['id' => $step->id, 'title' => $step->title, 'done' => $step->done_at !== null]);
    }

    /** The focus session running now, or null. */
    public function focus(Request $request): JsonResponse
    {
        return $this->ok(FocusActions::running($request->user())?->present());
    }

    /** {"task_id": 12, "minutes": 50}: both optional. A session already running is stopped first. */
    public function startFocus(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(FocusController::startRules($user));
        $task = isset($d['task_id']) ? Task::find($d['task_id']) : null;
        $session = $this->focus->start($user, $task, $d['minutes'] ?? null);

        return $this->ok($session->fresh('task')->present(), 201);
    }

    /** Stops the running session. Under a minute isn't kept (counted: false). */
    public function stopFocus(Request $request): JsonResponse
    {
        $session = FocusActions::running($request->user());
        abort_unless($session, 404, 'No focus session is running.');
        $seconds = $this->focus->finish($session);

        return $this->ok([
            'minutes' => (int) round($seconds / 60),
            'counted' => $seconds >= FocusActions::MIN_SECONDS,
            'task' => $session->task ? TaskPresenter::base($session->task->fresh()) : null,
        ]);
    }

    /** Dates in the user's own time zone → UTC; a plain due date means the end of that day. */
    private function dates(array $data, $user): array
    {
        foreach (['due_at', 'available_from', 'reminder_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->at($data[$key], $user, $key === 'due_at');
            }
        }

        return $data;
    }
}
