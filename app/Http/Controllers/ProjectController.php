<?php

namespace App\Http\Controllers;

use App\Actions\TaskActions;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskFile;
use App\Services\AutoArchive;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A project is its own little world: tasks and steps, habits, notes and milestones. */
class ProjectController extends Controller
{
    /** All projects: each with its status, progress, open tasks, due date and its best task's place in the ranking. */
    public function index(Request $request, RankingService $ranking): Response
    {
        $user = $request->user();
        $list = $ranking->rank($user);
        $best = [];
        foreach ($list->scored as $s) {
            $best[(int) ($list->tasks[(int) $s->task->id]->project_id ?? 0)] ??= $s->rank;
        }
        $projects = $user->projects()->with(['goal:id,title,color', 'area:id,name'])->withCount([
            'tasks as open_count' => fn ($q) => $q->whereIn('status', Task::OPEN),
            'tasks as done_count' => fn ($q) => $q->where('status', Task::DONE),
        ])->orderBy('name')->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->archived_at ? 'archived' : $p->status,
                'goal' => $p->goal ? ['title' => $p->goal->title, 'color' => $p->goal->color] : null,
                'area' => $p->area?->name,
                'open' => $p->open_count,
                'done' => $p->done_count,
                'pct' => $p->open_count + $p->done_count ? (int) round($p->done_count / ($p->open_count + $p->done_count) * 100) : 0,
                'dueDate' => $p->due_date?->toDateString(),
                'completedAt' => $p->completed_at?->toIso8601ZuluString(),
                'bestRank' => $best[$p->id] ?? null,
            ])->values(),
        ]);
    }

    /** New project (from the "+" in the Tasks menu). An area can be made on the way. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $project = $user->projects()->create($this->fields($request) + ['position' => (int) $user->projects()->max('position') + 1]);

        return to_route('projects.show', $project)->with('toast', "Created “{$project->name}”")->with('undo', $this->undo('delete-project', $project->id));
    }

    /** Only for undoing "New project": a project with nothing in it yet goes away for good. */
    /**
     * Deletes a project (Undo brings it back as it was). Its tasks go to the Inbox, or are
     * deleted too; its notes and habits stay, out of the project.
     */
    public function destroy(Request $request, Project $project, TaskActions $actions): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $mode = $request->validate(['tasks' => ['sometimes', Rule::in(['inbox', 'delete'])]])['tasks'] ?? 'inbox';
        $tasks = $project->tasks()->get();
        $was = ['mode' => $mode, 'tasks' => $tasks->pluck('id')->all(), 'notes' => $project->notes()->pluck('id')->all(), 'habits' => $project->habits()->pluck('id')->all()];
        DB::transaction(function () use ($project, $tasks, $mode, $user, $actions) {
            if ($mode === 'delete') {
                $tasks->each(fn (Task $t) => $actions->delete($t, $user));
            } else {
                $user->tasks()->whereIn('id', $tasks->pluck('id'))->update(['project_id' => null]);
            }
            $project->notes()->update(['project_id' => null]);
            $project->habits()->update(['project_id' => null]);
            $project->delete();
        });
        $n = $tasks->count();
        $what = $n === 0 ? '' : ($mode === 'delete' ? " and its {$n} ".($n === 1 ? 'task' : 'tasks') : " · {$n} ".($n === 1 ? 'task' : 'tasks').' moved to the Inbox');

        return to_route('tasks.index')->with('toast', "Deleted “{$project->name}”{$what}")->with('undo', $this->undo('restore-project', $project->id, $was));
    }

    /** Undo of deleting a project: it comes back, with its tasks, notes and habits in it again. */
    public function restore(Request $request, int $id, TaskActions $actions): RedirectResponse
    {
        $user = $request->user();
        $project = Project::onlyTrashed()->findOrFail($id);
        $this->own($project, $user);
        $was = $request->validate([
            'mode' => ['sometimes', Rule::in(['inbox', 'delete'])],
            'tasks' => ['nullable', 'array'], 'tasks.*' => ['integer'],
            'notes' => ['nullable', 'array'], 'notes.*' => ['integer'],
            'habits' => ['nullable', 'array'], 'habits.*' => ['integer'],
        ]);
        DB::transaction(function () use ($project, $was, $user, $actions) {
            $project->restore();
            if (($was['mode'] ?? 'inbox') === 'delete') {
                Task::onlyTrashed()->where('user_id', $user->id)->whereIn('id', $was['tasks'] ?? [])->get()->each(fn (Task $t) => $actions->restore($t, $user));
            } else {
                $user->tasks()->whereIn('id', $was['tasks'] ?? [])->whereNull('project_id')->update(['project_id' => $project->id]);
            }
            $user->notes()->whereIn('id', $was['notes'] ?? [])->whereNull('project_id')->update(['project_id' => $project->id]);
            $user->habits()->whereIn('id', $was['habits'] ?? [])->whereNull('project_id')->update(['project_id' => $project->id]);
        });

        return back()->with('toast', "“{$project->name}” is back");
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

    public static function fields(Request $request, bool $partial = false): array
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
        $project->load(['goal', 'area', 'milestones', 'notes', 'habits.entries', 'habits.project', 'people']);

        $list = $ranking->rank($user);
        $open = $list->toArray(fn (Task $t) => $t->project_id === $project->id);
        $notReady = $ranking->notReady($user, $project->id)->map(fn ($r) => TaskPresenter::notReady($r))->values();
        AutoArchive::run($user, $ranking->now());
        // Finished here: done and cancelled, the latest first (archived ones are out of the way).
        $done = $project->tasks()->whereIn('status', Task::FINISHED)->whereNull('archived_at')
            ->orderByRaw('coalesce(completed_at, cancelled_at) desc')->limit(30)->get();
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
                'status' => $project->status,
                'completedAt' => $project->completed_at?->toIso8601ZuluString(),
                'openCount' => $project->tasks()->whereIn('status', Task::OPEN)->count(),
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
            'people' => $project->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'initials' => $p->initials(), 'relationship' => $p->relationship])->values(),
            'peopleChoices' => $request->user()->people()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'notes' => $project->notes->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle(), 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values(),
            // The project's own files, and the files on its tasks (with the task they are on).
            'files' => $project->files()->get()->map(fn ($f) => $f->present() + ['task' => null])->concat(
                TaskFile::whereIn('task_id', $project->tasks()->whereNull('archived_at')->select('id'))->with('task:id,title')->latest('id')->limit(200)->get()
                    ->map(fn (TaskFile $f) => $f->present() + ['task' => ['id' => $f->task->id, 'title' => $f->task->title]])
            )->values(),
            // GitHub (once connected in Settings): the project's repository; its open issues load on their own.
            'github' => $user->github_login ? ['repo' => $project->github_repo] : null,
            // The task panel opens right here (?task=id), as on the Tasks pages.
            'selected' => fn () => app(TaskController::class)->detailFor($request),
            'projects' => $user->projects()->whereNull('archived_at')->get(['id', 'name']),
        ]);
    }
}
