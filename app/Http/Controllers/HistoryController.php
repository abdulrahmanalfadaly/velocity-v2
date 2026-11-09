<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** FR10: tasks completed per week and how many were finished on time. */
class HistoryController extends Controller
{
    public function __invoke(Request $request, Clock $clock): Response
    {
        $user = $request->user();
        $weekStart = $clock->now()->timezone($user->timezone)->startOfWeek();
        $from = $weekStart->subWeeks(11);
        $done = $user->tasks()->where('status', Task::DONE)->where('completed_at', '>=', $from->utc())->with('project')->latest('completed_at')->get();

        $weeks = collect(range(11, 0))->map(function ($ago) use ($weekStart, $done, $user) {
            $start = $weekStart->subWeeks($ago);
            $in = $done->filter(fn ($t) => $t->completed_at->timezone($user->timezone)->between($start, $start->endOfWeek()));
            $due = $in->whereNotNull('due_at');

            return [
                'start' => $start->toDateString(),
                'done' => $in->count(),
                'onTime' => $due->filter(fn ($t) => $t->completed_at <= $t->due_at)->count(),
                'withDue' => $due->count(),
            ];
        });
        $withDue = $done->whereNotNull('due_at');

        return Inertia::render($user->fyp_mode ? 'fyp/history' : 'history/index', [
            'weeks' => $weeks,
            'totals' => [
                'done' => $done->count(),
                'onTimePct' => $withDue->count() ? (int) round($withDue->filter(fn ($t) => $t->completed_at <= $t->due_at)->count() / $withDue->count() * 100) : null,
                'avgScore' => $done->whereNotNull('score_at_completion')->avg('score_at_completion'),
            ],
            'recent' => $done->take(20)->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name,
                'completedAt' => $t->completed_at->toIso8601ZuluString(), 'dueAt' => $t->due_at?->toIso8601ZuluString(),
                'onTime' => $t->due_at ? $t->completed_at <= $t->due_at : null, 'score' => $t->score_at_completion,
            ])->values(),
            // FYP mode also lists recent activity, as the first version did.
            'activity' => fn () => $user->fyp_mode
                ? TaskEvent::where('user_id', $user->id)->with('task:id,title')
                    ->latest('created_at')->latest('id')->limit(12)->get()
                    ->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'title' => $e->task?->title, 'at' => $e->created_at?->toIso8601ZuluString()])->values()
                : [],
        ]);
    }
}
