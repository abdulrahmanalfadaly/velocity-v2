<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Services\StudyLogger;
use App\Services\TaskPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Goals map: everything you are working towards, as one tree. */
class GoalController extends Controller
{
    public function index(Request $request, RankingService $ranking, StudyLogger $log): Response
    {
        $user = $request->user();
        $list = $ranking->rank($user);
        $log->ranking($user, $list, 'goals_map');
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

        $projectNode = function ($p) use ($taskNode, $todayLocal, $recentDone) {
            // Open tasks, plus the three most recent finished ones (faded on the map, to show progress).
            $recent = $p->tasks->filter(fn (Task $t) => $t->status === Task::DONE && $t->completed_at >= $recentDone)->sortByDesc('completed_at')->take(3);
            $tasks = $p->tasks->filter(fn (Task $t) => $t->isOpen())->concat($recent)->values();

            return [
                'id' => $p->id,
                'name' => $p->name,
                'done' => $p->tasks->where('status', Task::DONE)->count(),
                'total' => $p->tasks->whereIn('status', [...Task::OPEN, Task::DONE])->count(),
                'tasks' => $tasks->map($taskNode)->values(),
                'notes' => $p->notes->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values(),
                'habits' => $p->habits->map(fn ($h) => HabitStats::present($h, $todayLocal))->values(),
            ];
        };

        return Inertia::render('goals/index', [
            'goals' => $goals->map(fn ($g) => [
                'id' => $g->id, 'title' => $g->title, 'why' => $g->why, 'target' => $g->target, 'color' => $g->color, 'icon' => $g->icon,
                'projects' => $g->projects->map($projectNode)->values(),
            ])->values(),
            'inbox' => $user->tasks()->whereNull('project_id')->whereIn('status', Task::OPEN)->with('steps')->get()->map($taskNode)->values(),
            'nextId' => $list->scored[0]->task->id ?? null,
            'totalRanked' => count($list),
            'focus' => $request->query('focus'),
        ]);
    }
}
