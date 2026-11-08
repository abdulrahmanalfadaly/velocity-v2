<?php

namespace App\Services;

use App\Domain\Prioritization\ExplanationBuilder;
use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\TaskInput;
use App\Models\Task;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place tasks get ranked. Every list, suggestion and map in Velocity
 * asks this service, so they always agree.
 *
 * Eligibility comes first and is not part of the score: a task that is
 * waiting, blocked by an unfinished task, or not available yet is left out of
 * the ranking (and listed as "not ready"), never scored lower. Everything
 * else is scored by the report's formula (Algorithm 3.2).
 */
class RankingService
{
    public function __construct(
        private readonly PrioritizationEngine $engine,
        private readonly ExplanationBuilder $explainer,
        private readonly Clock $clock,
    ) {
    }

    /** @return Builder<Task> open tasks the formula may rank */
    public function eligibleQuery(User $user): Builder
    {
        $now = $this->clock->now();

        return Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Task::TODO, Task::DOING])
            ->where(fn (Builder $q) => $q->whereNull('available_from')->orWhere('available_from', '<=', $now))
            ->whereDoesntHave('dependencies', fn (Builder $q) => $q->whereNotIn('status', [Task::DONE, Task::CANCELLED]));
    }

    public function rank(User $user, ?RankingParameters $params = null): RankedList
    {
        $params ??= $user->weights()->parameters();
        $tasks = $this->eligibleQuery($user)
            ->with(['project.goal', 'parent:id,title', 'steps'])
            ->get()
            ->keyBy('id');

        $inputs = $tasks->map(fn (Task $t) => new TaskInput(
            $t->id,
            $t->title,
            $t->importance,
            $t->urgency,
            $t->due_at?->toDateTimeImmutable(),
            max(0.01, $t->est_hours),
            $t->created_at->toDateTimeImmutable(),
        ))->values()->all();

        $scored = $this->engine->rank($inputs, $this->clock->now()->toDateTimeImmutable(), $params);

        return new RankedList($scored, $tasks, $this->explainer, $params);
    }

    /**
     * Open tasks that are not ranked yet, with the reason.
     *
     * @return Collection<int, array{task: Task, kind: string, why: string}>
     */
    public function notReady(User $user, ?int $projectId = null): Collection
    {
        $now = $this->clock->now();
        $eligible = $this->eligibleQuery($user)->pluck('id')->flip();

        return Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', Task::OPEN)
            ->when($projectId, fn (Builder $q) => $q->where('project_id', $projectId))
            ->with(['project.goal', 'dependencies' => fn ($q) => $q->whereNotIn('status', [Task::DONE, Task::CANCELLED])])
            ->orderBy('created_at')
            ->get()
            ->reject(fn (Task $t) => $eligible->has($t->id))
            ->map(function (Task $t) use ($now) {
                if ($t->status === Task::WAITING) {
                    return ['task' => $t, 'kind' => 'waiting', 'why' => $t->waiting_for ? "Waiting for {$t->waiting_for}" : 'Waiting on someone else'];
                }
                if ($t->dependencies->isNotEmpty()) {
                    return ['task' => $t, 'kind' => 'blocked', 'why' => 'Starts after “'.$t->dependencies->first()->title.'”'];
                }

                return ['task' => $t, 'kind' => 'later', 'why' => 'Can start '.$t->available_from?->timezone($t->user->timezone ?? config('velocity.timezone'))->format('D, j M')];
            })
            ->values();
    }

    public function now()
    {
        return $this->clock->now();
    }
}
