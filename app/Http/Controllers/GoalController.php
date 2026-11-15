<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\NoteLink;
use App\Models\Task;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Goals map: everything you are working towards, as one tree. */
class GoalController extends Controller
{
    public const ICONS = ['target', 'cap', 'briefcase', 'heart', 'flame', 'zap'];

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $goal = $user->goals()->create($this->fields($request) + ['position' => (int) $user->goals()->max('position') + 1]);

        return back()->with('toast', "Created goal “{$goal->title}”")->with('undo', $this->undo('delete-goal', $goal->id));
    }

    /** Only for undoing "New goal": a goal with no projects yet goes away for good. */
    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        $this->own($goal, $request->user());
        if ($goal->projects()->exists()) {
            return back()->with('toast', "“{$goal->title}” already has projects – archive it instead");
        }
        $goal->delete();

        return back()->with('toast', "Removed goal “{$goal->title}”");
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        $this->own($goal, $request->user());
        $data = $this->fields($request, partial: true);
        $before = $goal->only(array_keys($data));
        $goal->update($data);

        return back()->with('toast', 'Goal saved')->with('undo', $this->undo('patch-goal', $goal->id, $before));
    }

    /** Archiving hides a goal from the map; its projects stay, just without a goal colour. */
    public function archive(Request $request, Goal $goal): RedirectResponse
    {
        $this->own($goal, $request->user());
        $goal->update(['archived_at' => now()]);

        return back()->with('toast', "Archived goal “{$goal->title}”")->with('undo', ['action' => 'unarchive-goal', 'id' => $goal->id]);
    }

    public function unarchive(Request $request, Goal $goal): RedirectResponse
    {
        $this->own($goal, $request->user());
        $goal->update(['archived_at' => null]);

        return back()->with('toast', "Restored goal “{$goal->title}”")->with('undo', $this->undo('archive-goal', $goal->id));
    }

    public static function fields(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        $data = $request->validate([
            'title' => [...$sometimes, 'required', 'string', 'max:160'],
            'why' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(Goal::STATUSES)],
            'target' => ['sometimes', 'nullable', 'string', 'max:60'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'progress_mode' => ['sometimes', Rule::in(Goal::PROGRESS)],
            'progress' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'color' => [...$sometimes, 'required', 'integer', 'between:1,3'],
            'icon' => ['sometimes', 'nullable', Rule::in(self::ICONS)],
        ], [], ['color' => 'colour', 'target_date' => 'target date']);
        // Only "by hand" keeps a number.
        if (array_key_exists('progress_mode', $data) && $data['progress_mode'] !== 'manual') {
            $data['progress'] = null;
        }

        return $data;
    }

    public function index(Request $request, RankingService $ranking): Response
    {
        $user = $request->user();
        $list = $ranking->rank($user);
        $ranked = collect($list->toArray())->keyBy('id');
        $notReady = $ranking->notReady($user)->map(fn ($r) => TaskPresenter::notReady($r))->keyBy('id');
        $todayLocal = $ranking->now()->timezone($user->timezone)->startOfDay();
        $recentDone = $ranking->now()->subDays(21);

        $goals = $user->goals()->whereNull('archived_at')->with([
            'projects' => fn ($q) => $q->whereNull('archived_at')->with(['notes', 'habits.entries', 'habits.project', 'tasks' => fn ($t) => $t->with('steps')]),
        ])->get();

        $taskNode = function (Task $t) use ($ranked, $notReady) {
            if (isset($ranked[$t->id])) {
                return ['kind' => 'ranked'] + $ranked[$t->id];
            }
            if (isset($notReady[$t->id])) {
                return $notReady[$t->id];
            }

            return TaskPresenter::base($t) + ['kind' => 'done', 'why' => 'Done '.$t->completed_at?->toIso8601ZuluString()];
        };

        // Open tasks, plus the three most recent finished ones (faded on the map, to show progress).
        $shown = function ($tasks) use ($recentDone) {
            $recent = $tasks->filter(fn (Task $t) => $t->status === Task::DONE && $t->completed_at >= $recentDone)->sortByDesc('completed_at')->take(3);

            return $tasks->filter(fn (Task $t) => $t->isOpen())->concat($recent)->values();
        };

        $projectNode = function ($p) use ($taskNode, $todayLocal, $shown) {
            $tasks = $shown($p->tasks);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'done' => $p->tasks->where('status', Task::DONE)->count(),
                'total' => $p->tasks->whereIn('status', [...Task::OPEN, Task::DONE])->count(),
                'tasks' => $tasks->map($taskNode)->values(),
                'notes' => $p->notes->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle(), 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values(),
                'habits' => $p->habits->map(fn ($h) => HabitStats::present($h, $todayLocal))->values(),
            ];
        };

        // Projects with no goal (or whose goal is archived) get their own branch, so none of their tasks go missing.
        $other = $user->projects()->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('goal_id')->orWhereHas('goal', fn ($g) => $g->whereNotNull('archived_at')))
            ->with(['notes', 'habits.entries', 'habits.project', 'tasks' => fn ($t) => $t->with('steps')])->get();
        // Tasks that belong straight to a goal (no project).
        $direct = $user->tasks()->whereNull('project_id')->whereIn('goal_id', $goals->pluck('id'))->with('steps')->get()->groupBy('goal_id');
        $branches = $goals->map(fn ($g) => [
            'id' => $g->id, 'title' => $g->title, 'why' => $g->why, 'target' => $g->target, 'color' => $g->color, 'icon' => $g->icon,
            'status' => $g->status, 'targetDate' => $g->target_date?->toDateString(), 'progressMode' => $g->progress_mode, 'progress' => $g->progress,
            // Milestones or by hand: the % the map shows instead of counting tasks.
            'progressPct' => $g->progressPct(),
            'projects' => $g->projects->map($projectNode)->values(),
            'tasks' => $shown($direct->get($g->id, collect()))->map($taskNode)->values(),
            // Notes linked to the goal (a note's "Link" picker).
            'notes' => NoteLink::notesFor($user, 'goal', $g->id),
        ])->values();
        if ($other->isNotEmpty()) {
            $branches->push(['id' => 0, 'title' => 'Other projects', 'why' => null, 'target' => null, 'color' => 0, 'icon' => 'folder', 'projects' => $other->map($projectNode)->values(), 'notes' => []]);
        }

        return Inertia::render('goals/index', [
            'goals' => $branches,
            // Open tasks with no project (and no goal on the map), or in an archived project.
            'inbox' => $user->tasks()->whereIn('status', Task::OPEN)
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('project_id')->where(fn ($g) => $g->whereNull('goal_id')->orWhereNotIn('goal_id', $goals->pluck('id'))))
                    ->orWhereHas('project', fn ($p) => $p->whereNotNull('archived_at')))
                ->with('steps')->get()->map($taskNode)->values(),
            'nextId' => $list->scored[0]->task->id ?? null,
            'totalRanked' => count($list),
            'focus' => $request->query('focus'),
        ]);
    }
}
