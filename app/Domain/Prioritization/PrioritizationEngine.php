<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

use DateTimeImmutable;

/**
 * Algorithm 3.2 – RankTasks: Simple Additive Weighting over four criteria
 * normalised against fixed bounds.
 *
 * Every normalised value depends only on the task itself and the clock, never
 * on the other tasks, so inserting or completing a task cannot reorder the rest
 * (no rank reversal, FR7).
 */
final class PrioritizationEngine
{
    private const SECONDS_PER_DAY = 86400.0;
    private const SCORE_EPSILON = 1e-9;

    /**
     * Normalised criterion values c (0..1) for one task – Table 3.2.
     *
     * @return array{criteria: array<string, float>, days: ?float}
     */
    public function normalise(TaskInput $task, DateTimeImmutable $now, RankingParameters $params): array
    {
        $cI = ($task->importance - 1) / 4;
        $cU = ($task->urgency - 1) / 4;
        $days = null;

        if ($task->dueAt === null) {
            // No deadline: no proximity, pressure assessed over a 30-day horizon.
            $cD = 0.0;
            $cP = min(1.0, $task->estHours / ($params->hoursPerDay * RankingParameters::NO_DUE_DATE_DAYS));
        } else {
            $days = ($task->dueAt->getTimestamp() - $now->getTimestamp()) / self::SECONDS_PER_DAY;
            if ($days <= 0) {
                // Overdue.
                $cD = 1.0;
                $cP = 1.0;
            } else {
                $cD = 1 / (1 + $days / $params->horizonDays);
                $cP = min(1.0, $task->estHours / ($params->hoursPerDay * max($days, 1.0)));
            }
        }

        return [
            'criteria' => [
                Criteria::IMPORTANCE => (float) $cI,
                Criteria::URGENCY => (float) $cU,
                Criteria::DEADLINE => (float) $cD,
                Criteria::PRESSURE => (float) $cP,
            ],
            'days' => $days,
        ];
    }

    /** Scores a single task: S = 100 × Σ w_j c_j, with each term its contribution K_j. */
    public function score(TaskInput $task, DateTimeImmutable $now, RankingParameters $params): ScoredTask
    {
        $normalised = $this->normalise($task, $now, $params);
        $contributions = [];
        foreach (Criteria::KEYS as $key) {
            $contributions[$key] = 100 * $params->weights->get($key) * $normalised['criteria'][$key];
        }

        return new ScoredTask(
            $task,
            $normalised['criteria'],
            $contributions,
            array_sum($contributions),
            $normalised['days'],
        );
    }

    /**
     * Scores and ranks the open tasks of one student.
     *
     * @param iterable<TaskInput> $tasks
     * @return list<ScoredTask> sorted, ranks assigned 1..n
     */
    public function rank(iterable $tasks, DateTimeImmutable $now, RankingParameters $params): array
    {
        $scored = [];
        foreach ($tasks as $task) {
            $scored[] = $this->score($task, $now, $params);
        }

        usort($scored, [self::class, 'compare']);

        $ranked = [];
        foreach ($scored as $index => $item) {
            $ranked[] = $item->withRank($index + 1);
        }

        return $ranked;
    }

    /**
     * Sort order: score descending; ties broken by earlier due date (tasks
     * without one last), then shorter duration, then earlier creation time.
     * The id is a final deterministic tie-breaker.
     */
    public static function compare(ScoredTask $a, ScoredTask $b): int
    {
        if (abs($a->score - $b->score) > self::SCORE_EPSILON) {
            return $a->score > $b->score ? -1 : 1;
        }

        return self::compareTieBreakers($a, $b);
    }

    public static function compareTieBreakers(ScoredTask $a, ScoredTask $b): int
    {
        $dueA = $a->task->dueAt?->getTimestamp();
        $dueB = $b->task->dueAt?->getTimestamp();
        if ($dueA !== $dueB) {
            if ($dueA === null) {
                return 1;
            }
            if ($dueB === null) {
                return -1;
            }

            return $dueA <=> $dueB;
        }

        if (abs($a->task->estHours - $b->task->estHours) > 1e-9) {
            return $a->task->estHours <=> $b->task->estHours;
        }

        $createdOrder = $a->task->createdAt->getTimestamp() <=> $b->task->createdAt->getTimestamp();
        if ($createdOrder !== 0) {
            return $createdOrder;
        }

        return strcmp((string) $a->task->id, (string) $b->task->id);
    }

    /**
     * The first three tasks of a ranked list – the "focus" panel.
     *
     * @param list<ScoredTask> $ranked
     * @return list<ScoredTask>
     */
    public static function focus(array $ranked, int $size = 3): array
    {
        return array_slice($ranked, 0, $size);
    }
}
