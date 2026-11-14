<?php

namespace App\Services;

use App\Models\User;

/**
 * Next actions: each project's best task by the formula (its first in the one
 * ranking), projects in the order of those tasks, and the projects with
 * nothing ready, with why. Shared by the Next actions page and the API.
 */
class NextActions
{
    public function __construct(private readonly RankingService $ranking) {}

    /** @return array{rows: list<array>, idle: list<array>} */
    public function for(User $user, ?callable $present = null): array
    {
        $list = $this->ranking->rank($user);
        $best = [];
        foreach ($list->scored as $k => $s) {
            $pid = (int) ($list->tasks[(int) $s->task->id]->project_id ?? 0);
            $best[$pid] ??= $present ? $present($list->tasks[(int) $s->task->id], $list) : $list->present($k);
        }
        $projects = $user->projects()->whereNull('archived_at')->with('goal:id,color')->orderBy('name')->get();
        $notReady = $this->ranking->notReady($user)->groupBy(fn ($r) => (int) ($r['task']->project_id ?? 0));
        $why = function (int $pid) use ($notReady, $projects): string {
            $kinds = collect($notReady[$pid] ?? [])->countBy('kind');
            if ($kinds['held'] ?? 0) {
                return $projects->firstWhere('id', $pid)?->status === 'planned' ? 'Project planned' : 'Project on hold';
            }
            $parts = array_filter([
                ($kinds['waiting'] ?? 0) ? $kinds['waiting'].' waiting' : null,
                ($kinds['blocked'] ?? 0) ? $kinds['blocked'].' after another task' : null,
                ($kinds['later'] ?? 0) ? $kinds['later'].' starting later' : null,
            ]);

            return $parts ? implode(' · ', $parts) : 'No open tasks';
        };
        $project = fn (int $pid) => $projects->firstWhere('id', $pid);

        return [
            'rows' => collect($best)
                ->filter(fn ($task, $pid) => $pid === 0 || $project($pid) !== null)
                ->map(fn ($task, $pid) => [
                    'project' => $pid ? ['id' => $pid, 'name' => $project($pid)->name, 'goalColor' => $project($pid)->goal?->color] : null,
                    'task' => $task,
                ])->values()->all(),
            'idle' => $projects->filter(fn ($p) => ! isset($best[$p->id]) && $p->status !== 'completed')->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'goalColor' => $p->goal?->color, 'why' => $why($p->id),
            ])->values()->all(),
        ];
    }
}
