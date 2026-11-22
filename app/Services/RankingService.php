<?php

namespace App\Services;

use App\Domain\Prioritization\ExplanationBuilder;
use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\TaskInput;
use App\Models\Project;
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
    ) {}

    /** @return Builder<Task> open tasks the formula may rank */
    public function eligibleQuery(User $user): Builder
    {
        $now = $this->clock->now();

        return Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Task::TODO, Task::DOING])
            ->whereNull('captured_at') // captured only: waits in the Inbox's "To sort" until "Rank it"
            ->where(fn (Builder $q) => $q->whereNull('available_from')->orWhere('available_from', '<=', $now))
            // "Starts after" another task: only FYP mode, as the report has it. In personal mode nothing blocks anything.
            ->when($user->fyp_mode, fn (Builder $q) => $q->whereDoesntHave('dependencies', fn (Builder $d) => $d->whereNotIn('status', [Task::DONE, Task::CANCELLED])))
            // A planned or on-hold project's tasks wait (personal mode; FYP mode stays as the report has it).
            ->when(! $user->fyp_mode, fn (Builder $q) => $q->whereDoesntHave('project', fn (Builder $p) => $p->whereIn('status', Project::HELD)));
    }

    public function rank(User $user, ?RankingParameters $params = null): RankedList
    {
        $params ??= $user->weights()->parameters();
        $tasks = $this->eligibleQuery($user)
            ->with(['project.goal', 'goal', 'parent:id,title', 'steps'])
            ->withCount(['files', 'children', 'children as children_finished_count' => fn (Builder $q) => $q->whereIn('status', Task::FINISHED)])
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
        $today = $now->timezone($user->timezone)->startOfDay();
        $eligible = $this->eligibleQuery($user)->pluck('id')->flip();

        return Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', Task::OPEN)
            ->whereNull('captured_at')
            ->when($projectId, fn (Builder $q) => $q->where('project_id', $projectId))
            ->with(['project.goal', 'goal', 'dependencies' => fn ($q) => $q->whereNotIn('status', [Task::DONE, Task::CANCELLED])])
            ->orderBy('created_at')
            ->get()
            ->reject(fn (Task $t) => $eligible->has($t->id))
            ->map(function (Task $t) use ($today, $user) {
                if (! $user->fyp_mode && $t->project && in_array($t->project->status, Project::HELD, true)) {
                    return ['task' => $t, 'kind' => 'held', 'why' => $t->project->status === 'planned' ? 'Project planned' : 'Project on hold'];
                }
                if ($t->status === Task::WAITING) {
                    $since = $user->fyp_mode ? null : FollowUps::waitingDays($t, $today); // FYP mode stays as the report has it

                    return ['task' => $t, 'kind' => 'waiting', 'why' => ($t->waiting_for ? "Waiting for {$t->waiting_for}" : 'Waiting on someone else')
                        .($since ? ' · '.FollowUps::days($since) : '')];
                }
                if ($user->fyp_mode && $t->dependencies->isNotEmpty()) {
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
