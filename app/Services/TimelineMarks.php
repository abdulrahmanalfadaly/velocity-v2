<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What the Timeline draws besides tasks: the goals (one line each, in their own order) and the
 * landmarks of the next twelve months — milestones, project due dates and goal target dates.
 * On a project's page only that project's own milestones and due date.
 */
final class TimelineMarks
{
    /**
     * @return array{goals: list<array{id: int, title: string, color: int}>, marks: list<array{key: string, date: string, kind: string, title: string, href: string, goalId: int|null}>}
     */
    public static function for(User $user, CarbonImmutable $today, ?Project $only = null): array
    {
        $first = $today->toDateString();
        $last = $today->addYear()->toDateString();
        $marks = [];

        $projects = $only ? collect([$only]) : $user->projects()->whereNull('archived_at')->where('status', '!=', 'completed')
            ->whereDate('due_date', '>=', $first)->whereDate('due_date', '<=', $last)->get(['id', 'name', 'due_date', 'goal_id']);
        foreach ($projects as $p) {
            if ($p->due_date && $p->due_date->toDateString() >= $first && $p->due_date->toDateString() <= $last) {
                $marks[] = ['key' => "p{$p->id}", 'date' => $p->due_date->toDateString(), 'kind' => 'project', 'title' => "{$p->name} due", 'href' => "/projects/{$p->id}", 'goalId' => $p->goal_id];
            }
        }

        $owners = $only ? collect([$only->id]) : $user->projects()->whereNull('archived_at')->pluck('id');
        $milestones = Milestone::whereIn('project_id', $owners)->whereNull('done_at')
            ->whereDate('due_date', '>=', $first)->whereDate('due_date', '<=', $last)->with('project:id,goal_id')->get();
        foreach ($milestones as $m) {
            $marks[] = ['key' => "m{$m->id}", 'date' => $m->due_date->toDateString(), 'kind' => 'milestone', 'title' => $m->title, 'href' => "/projects/{$m->project_id}", 'goalId' => $m->project?->goal_id];
        }

        if (! $only) {
            $targets = $user->goals()->whereNull('archived_at')->whereNull('achieved_at')
                ->whereDate('target_date', '>=', $first)->whereDate('target_date', '<=', $last)->get(['id', 'title', 'target_date']);
            foreach ($targets as $g) {
                $marks[] = ['key' => "g{$g->id}", 'date' => $g->target_date->toDateString(), 'kind' => 'goal', 'title' => $g->title, 'href' => "/goals?focus=g{$g->id}", 'goalId' => $g->id];
            }
        }

        usort($marks, fn ($a, $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return [
            'goals' => $user->goals()->whereNull('archived_at')->orderBy('position')->orderBy('id')->get(['id', 'title', 'color'])
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'color' => (int) $g->color])->values()->all(),
            'marks' => $marks,
        ];
    }
}
