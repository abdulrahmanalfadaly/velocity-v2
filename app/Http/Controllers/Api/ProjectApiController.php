<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\GoalController;
use App\Http\Controllers\ProjectController;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Support\ApiPresent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Projects (with their milestones), goals. */
class ProjectApiController extends ApiController
{
    /** Projects in use; ?archived=1 for the archived ones too. */
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->projects()->with(['goal', 'area'])
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->whereIn('status', Task::OPEN)])
            ->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')->get();

        return $this->ok($projects->map(fn (Project $p) => ApiPresent::project($p))->values());
    }

    /** A project, its milestones, and (with Tasks: read) its open tasks in rank order. */
    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $project->load(['goal', 'area', 'milestones'])->loadCount(['tasks as open_tasks_count' => fn ($q) => $q->whereIn('status', Task::OPEN)]);
        $out = ApiPresent::project($project) + [
            'milestones' => $project->milestones->map(fn (Milestone $m) => [
                'id' => $m->id, 'title' => $m->title, 'dueDate' => $m->due_date?->toDateString(), 'done' => $m->done_at !== null,
            ])->values(),
        ];
        if ($this->can($request, 'tasks:read')) {
            $list = $this->ranking->rank($user);
            $out['tasks'] = $project->tasks()->whereIn('status', Task::OPEN)->with('project.goal')->get()
                ->map(fn (Task $t) => ApiPresent::task($t, $list))
                ->sortBy(fn ($t) => $t['rank'] ?? PHP_INT_MAX)->values();
        }

        return $this->ok($out);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $project = $user->projects()->create(ProjectController::fields($request) + ['position' => (int) $user->projects()->max('position') + 1]);

        return $this->ok(ApiPresent::project($project->load(['goal', 'area'])), 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->own($project, $request->user());
        $project->update(ProjectController::fields($request, partial: true));

        return $this->ok(ApiPresent::project($project->fresh(['goal', 'area'])));
    }

    /** {"done": true}, or a new title / due_date. */
    public function updateMilestone(Request $request, Milestone $milestone): JsonResponse
    {
        $this->own($milestone->project, $request->user());
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'done' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('done', $data)) {
            $data['done_at'] = $data['done'] ? now() : null;
            unset($data['done']);
        }
        $milestone->update($data);

        return $this->ok(['id' => $milestone->id, 'title' => $milestone->title, 'dueDate' => $milestone->due_date?->toDateString(), 'done' => $milestone->done_at !== null]);
    }

    public function goals(Request $request): JsonResponse
    {
        $goals = $request->user()->goals()->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))->orderBy('position')->get();

        return $this->ok($goals->map(fn (Goal $g) => ApiPresent::goal($g))->values());
    }

    /** {"title": "Graduate with first class", "why": …, "target": "June 2027"}; the colour is picked if not given. */
    public function storeGoal(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->mergeIfMissing(['color' => $user->goals()->count() % 3 + 1]);
        $goal = $user->goals()->create(GoalController::fields($request) + ['position' => (int) $user->goals()->max('position') + 1]);

        return $this->ok(ApiPresent::goal($goal), 201);
    }

    public function updateGoal(Request $request, Goal $goal): JsonResponse
    {
        $this->own($goal, $request->user());
        $goal->update(GoalController::fields($request, partial: true));

        return $this->ok(ApiPresent::goal($goal->fresh()));
    }
}
