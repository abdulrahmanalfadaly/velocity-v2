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
        $ranked = (clone $eligible)->count();
        $notReady = Task::where('user_id', $user->id)->whereIn('status', Task::OPEN)->count() - $ranked;

        $projects = $user->projects()->whereNull('archived_at')->with('goal:id,color')->orderBy('position')->orderBy('id')->get();
        $item = fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'goalColor' => $p->goal?->color,
            'open' => (int) ($openByProject[$p->id] ?? 0),
        ];
        $goals = $user->goals()->whereNull('archived_at')->orderBy('position')->get(['id', 'title', 'color']);

        return [
            'counts' => [
                'upNext' => $ranked,
                'inbox' => (int) ($openByProject[''] ?? $openByProject[null] ?? 0),
                'waiting' => max(0, $notReady),
                'goals' => $goals->count(),
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
