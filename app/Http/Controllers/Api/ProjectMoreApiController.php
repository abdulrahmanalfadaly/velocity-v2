<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProjectActions;
use App\Http\Controllers\AreaController;
use App\Models\Area;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Services\RankingService;
use App\Support\ApiPresent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Projects, more: status, completing, a project's ranked tasks, and areas. */
class ProjectMoreApiController extends ApiController
{
    public function __construct(RankingService $ranking, private readonly ProjectActions $projects)
    {
        parent::__construct($ranking);
    }

    /** {"status": "planned" | "active" | "on_hold"}. */
    public function status(Request $request, Project $project): JsonResponse
    {
        $this->own($project, $request->user());
        $status = $request->validate(['status' => ['required', Rule::in(['planned', 'active', 'on_hold'])]])['status'];
        $this->projects->setStatus($project, $status);

        return $this->ok($this->present($project));
    }

    /** {"open": "cancel" | "keep"}: what happens to its open tasks. */
    public function complete(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $open = $request->validate(['open' => ['required', Rule::in(['cancel', 'keep'])]])['open'];
        if ($project->status === 'completed') {
            throw ValidationException::withMessages(['project' => 'It is completed already.']);
        }
        $cancelled = $this->projects->complete($project, $user, $open === 'cancel');

        return $this->ok($this->present($project) + ['cancelledTasks' => $cancelled]);
    }

    /** {"tasks": "inbox" | "delete"}: its tasks go to the Inbox (the default) or are deleted too. meta.undo brings it back. */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $mode = $request->validate(['tasks' => ['sometimes', Rule::in(['inbox', 'delete'])]])['tasks'] ?? 'inbox';
        $was = $this->projects->delete($project, $user, $mode);

        return $this->ok(['id' => $project->id, 'deleted' => true], meta: ['undo' => $was]);
    }

    /** Send back what the delete answered in meta.undo. */
    public function restore(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $project = Project::onlyTrashed()->findOrFail($id);
        $this->own($project, $user);
        $this->projects->restore($project, $user, $request->validate(ProjectActions::UNDO_RULES));

        return $this->ok($this->present($project->fresh()));
    }

    /** Its projects and tasks stay, without a goal. meta.undo brings it back. */
    public function destroyGoal(Request $request, Goal $goal): JsonResponse
    {
        $user = $request->user();
        $this->own($goal, $user);

        return $this->ok(['id' => $goal->id, 'deleted' => true], meta: ['undo' => $this->projects->deleteGoal($goal, $user)]);
    }

    public function restoreGoal(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $goal = Goal::onlyTrashed()->findOrFail($id);
        $this->own($goal, $user);
        $this->projects->restoreGoal($goal, $user, $request->validate(ProjectActions::UNDO_RULES));

        return $this->ok(['id' => $goal->id, 'title' => $goal->title, 'deleted' => false]);
    }

    public function reopen(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $this->projects->reopen($project, $user);

        return $this->ok($this->present($project));
    }

    /** The project's open tasks in the one ranking's order (with their place in it), then the ones not ready. */
    public function tasks(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $list = $this->ranking->rank($user);
        $ranked = $project->tasks()->whereIn('id', array_keys($list->tasks->all()))->with('project.goal')->get()
            ->sortBy(fn (Task $t) => $list->rankOf($t->id))->map(fn (Task $t) => ApiPresent::task($t, $list))->values();
        $notReady = $this->ranking->notReady($user, $project->id)->map(fn ($r) => ApiPresent::task($r['task']) + ['kind' => $r['kind'], 'why' => $r['why']])->values();

        return $this->ok(['ranked' => $ranked, 'notReady' => $notReady]);
    }

    public function areas(Request $request): JsonResponse
    {
        return $this->ok($request->user()->areas()->withCount('projects')->orderBy('position')->get()
            ->map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name, 'icon' => $a->icon, 'projects' => $a->projects_count])->values());
    }

    /** {"name": "University", "icon": "cap"}. */
    public function storeArea(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'icon' => ['nullable', Rule::in(AreaController::ICONS)]]);
        $area = $user->areas()->create($d + ['position' => (int) $user->areas()->max('position') + 1]);

        return $this->ok(['id' => $area->id, 'name' => $area->name, 'icon' => $area->icon], 201);
    }

    public function updateArea(Request $request, Area $area): JsonResponse
    {
        $this->own($area, $request->user());
        $area->update($request->validate(['name' => ['sometimes', 'required', 'string', 'max:80'], 'icon' => ['sometimes', 'nullable', Rule::in(AreaController::ICONS)]]));

        return $this->ok(['id' => $area->id, 'name' => $area->name, 'icon' => $area->icon]);
    }

    private function present(Project $project): array
    {
        $project = $project->fresh(['goal', 'extraGoals', 'area']);

        return ApiPresent::project($project) + ['status' => $project->status, 'completedAt' => $project->completed_at?->toIso8601ZuluString()];
    }
}
