<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

/** Counts and the project tree for the side menus (shared with every page). */
class NavData
{
    public function __construct(private readonly RankingService $ranking) {}

    public function for(User $user): array
    {
        $eligible = $this->ranking->eligibleQuery($user);
        $openByProject = (clone $eligible)->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');
        // The views in the Tasks menu: how many ranked tasks each one shows (filters only; the order stays the formula's).
        $now = $this->ranking->now();
        $today = $now->timezone($user->timezone)->startOfDay();
        $views = (clone $eligible)->toBase()->selectRaw(
            'sum(case when due_at >= ? and due_at < ? then 1 else 0 end) as today, '
            .'sum(case when due_at < ? then 1 else 0 end) as week, '
            .'sum(case when due_at < ? then 1 else 0 end) as overdue, '
            .'sum(case when est_hours <= 1 then 1 else 0 end) as quick, '
            ."sum(case when status = 'doing' then 1 else 0 end) as doing, "
            .'sum(case when importance >= 4 then 1 else 0 end) as important, '
            .'sum(case when repeat_unit is not null then 1 else 0 end) as repeating',
            [$today->utc(), $today->addDay()->utc(), $today->addDays(8)->utc(), $now->utc()],
        )->first();
        $ranked = (clone $eligible)->count();
        $notReady = Task::where('user_id', $user->id)->whereIn('status', Task::OPEN)->count() - $ranked;

        // Completed projects leave the menu (All projects lists them).
        $projects = $user->projects()->whereNull('archived_at')->where('status', '!=', 'completed')->with('goal:id,color')->orderBy('position')->orderBy('id')->get();
        $item = fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'goalColor' => $p->goal?->color,
            'open' => (int) ($openByProject[$p->id] ?? 0),
            'status' => $p->status,
        ];
        $goals = $user->goals()->whereNull('archived_at')->orderBy('position')->get(['id', 'title', 'color']);

        return [
            'counts' => [
                'upNext' => $ranked,
                'inbox' => (int) ($openByProject[''] ?? $openByProject[null] ?? 0),
                'waiting' => max(0, $notReady),
                'goals' => $goals->count(),
                'views' => collect((array) $views)->map(fn ($n) => (int) $n)->all(),
            ],
            'areas' => $user->areas()->orderBy('position')->get()->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'icon' => $a->icon,
                'projects' => $projects->where('area_id', $a->id)->map($item)->values(),
            ])->values(),
            // Projects that are not in any area.
            'loose' => $projects->whereNull('area_id')->map($item)->values(),
            'goals' => $goals->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'color' => $g->color])->values(),
        ];
    }
}
