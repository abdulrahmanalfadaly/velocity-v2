<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

/** Counts and the project tree for the side menus (shared with every page). */
class NavData
{
    public function __construct(private readonly RankingService $ranking)
    {
    }

    public function for(User $user): array
    {
        $eligible = $this->ranking->eligibleQuery($user);
        $openByProject = (clone $eligible)->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');
        $ranked = (clone $eligible)->count();
        $notReady = Task::where('user_id', $user->id)->whereIn('status', Task::OPEN)->count() - $ranked;

        $areas = $user->areas()->with(['projects' => fn ($q) => $q->whereNull('archived_at')->with('goal:id,color')])->get();

        return [
            'counts' => [
                'upNext' => $ranked,
                'inbox' => (int) ($openByProject[''] ?? $openByProject[null] ?? 0),
                'waiting' => max(0, $notReady),
                'goals' => $user->goals()->whereNull('archived_at')->count(),
            ],
            'areas' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'icon' => $a->icon,
                'projects' => $a->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'goalColor' => $p->goal?->color,
                    'open' => (int) ($openByProject[$p->id] ?? 0),
                ])->values(),
            ])->values(),
        ];
    }
}
