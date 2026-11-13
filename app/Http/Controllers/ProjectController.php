<?php

namespace App\Http\Controllers;

use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A project is its own little world: tasks and steps, habits, notes and milestones. */
class ProjectController extends Controller
{
    /** New project (from the "+" in the Tasks menu). An area can be made on the way. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $project = $user->projects()->create($this->fields($request) + ['position' => (int) $user->projects()->max('position') + 1]);

        return to_route('projects.show', $project)->with('toast', "Created “{$project->name}”")->with('undo', $this->undo('delete-project', $project->id));
    }

    /** Only for undoing "New project": a project with nothing in it yet goes away for good. */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        if ($project->tasks()->withTrashed()->exists() || $project->notes()->exists() || $project->habits()->exists()) {
            return back()->with('toast', "“{$project->name}” already has things in it – archive it instead");
        }
        $project->milestones()->withTrashed()->forceDelete();
        $project->delete();

        return to_route('tasks.index')->with('toast', "Removed “{$project->name}”");
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $data = $this->fields($request, partial: true);
        $before = $project->only(array_keys($data));
        if (array_key_exists('due_date', $before)) {
            $before['due_date'] = $project->due_date?->toDateString();
        }
        $project->update($data);

        return back()->with('toast', 'Project saved')->with('undo', $this->undo('patch-project', $project->id, $before));
    }

    /** Archiving hides a project from the menus and the Goals map; its open tasks stay in your list. */
    public function archive(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $project->update(['archived_at' => now()]);

        return back()->with('toast', "Archived “{$project->name}”")->with('undo', ['action' => 'unarchive-project', 'id' => $project->id]);
    }

    public function unarchive(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $project->update(['archived_at' => null]);

        return back()->with('toast', "Restored “{$project->name}”")->with('undo', $this->undo('archive-project', $project->id));
    }

    private function fields(Request $request, bool $partial = false): array
    {
        $user = $request->user();
        $sometimes = $partial ? ['sometimes'] : [];
        $data = $request->validate([
            'name' => [...$sometimes, 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'goal_id' => ['sometimes', 'nullable', 'integer', Rule::exists('goals', 'id')->where('user_id', $user->id)],
            'area_id' => ['sometimes', 'nullable', 'integer', Rule::exists('areas', 'id')->where('user_id', $user->id)],
            'new_area' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], [], ['new_area' => 'area name', 'goal_id' => 'goal', 'area_id' => 'area']);
        // "New area…" in the form: make it and put the project in it.
        if (filled($data['new_area'] ?? null)) {
            $data['area_id'] = $user->areas()->firstOrCreate(['name' => trim($data['new_area'])], ['position' => (int) $user->areas()->max('position') + 1])->id;
        }
        unset($data['new_area']);

        return $data;
    }

    public function show(Request $request, Project $project, RankingService $ranking): Response
    {
        $user = $request->user();
        $this->own($project, $user);
        $project->load(['goal', 'area', 'milestones', 'notes', 'habits.entries', 'habits.project']);

        $list = $ranking->rank($user);
        $open = $list->toArray(fn (Task $t) => $t->project_id === $project->id);
        $notReady = $ranking->notReady($user, $project->id)->map(fn ($r) => TaskPresenter::notReady($r))->values();
        $done = $project->tasks()->where('status', Task::DONE)->latest('completed_at')->limit(30)->get();
        $total = count($open) + $notReady->count() + $project->tasks()->where('status', Task::DONE)->count();
        $doneCount = $project->tasks()->where('status', Task::DONE)->count();
        $todayLocal = $ranking->now()->timezone($user->timezone)->startOfDay();

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'dueDate' => $project->due_date?->toDateString(),
                // Whole days between today and the due date, both as dates in the user's own time zone.
                'daysLeft' => $project->due_date
                    ? (int) round($todayLocal->diffInDays(CarbonImmutable::parse($project->due_date->toDateString(), $user->timezone), false))
                    : null,
                'area' => $project->area?->name,
                'areaId' => $project->area_id,
                'goal' => $project->goal ? ['id' => $project->goal->id, 'title' => $project->goal->title, 'color' => $project->goal->color] : null,
                'archived' => $project->archived_at !== null,
            ],
            'stats' => [
                'done' => $doneCount, 'total' => $total, 'pct' => $total ? (int) round($doneCount / $total * 100) : 0, 'bestRank' => $open[0]['rank'] ?? null,
                // Focused on this project's tasks since Monday, in the user's own week.
                'focusedWeek' => (int) FocusSession::whereNotNull('ended_at')->whereIn('task_id', $project->tasks()->withTrashed()->select('id'))
                    ->where('started_at', '>=', $todayLocal->startOfWeek()->utc())->sum('seconds'),
            ],
            'milestones' => $project->milestones->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title, 'dueDate' => $m->due_date?->toDateString(), 'done' => $m->done_at !== null,
            ])->values(),
            'tasks' => $open,
            'totalRanked' => count($list),
            'notReady' => $notReady,
            'done' => $done->map(fn ($t) => TaskPresenter::base($t))->values(),
            'habits' => $project->habits->map(fn ($h) => HabitStats::present($h, $todayLocal))->values(),
            'notes' => $project->notes->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values(),
            // The task panel opens right here (?task=id), as on the Tasks pages.
            'selected' => fn () => app(TaskController::class)->detailFor($request),
            'projects' => $user->projects()->whereNull('archived_at')->get(['id', 'name']),
        ]);
    }
}
