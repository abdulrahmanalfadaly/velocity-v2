<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A project is its own little world: tasks and steps, habits, notes and milestones. */
class ProjectController extends Controller
{
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
                'daysLeft' => $project->due_date ? (int) $todayLocal->diffInDays($project->due_date->copy()->startOfDay(), false) : null,
                'area' => $project->area?->name,
                'goal' => $project->goal ? ['id' => $project->goal->id, 'title' => $project->goal->title, 'color' => $project->goal->color] : null,
            ],
            'stats' => ['done' => $doneCount, 'total' => $total, 'pct' => $total ? (int) round($doneCount / $total * 100) : 0, 'bestRank' => $open[0]['rank'] ?? null],
            'milestones' => $project->milestones->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title, 'dueDate' => $m->due_date?->toDateString(), 'done' => $m->done_at !== null,
            ])->values(),
            'tasks' => $open,
            'totalRanked' => count($list),
            'notReady' => $notReady,
            'done' => $done->map(fn ($t) => TaskPresenter::base($t))->values(),
            'habits' => $project->habits->map(fn ($h) => HabitStats::present($h, $todayLocal))->values(),
            'notes' => $project->notes->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values(),
        ]);
    }
}
