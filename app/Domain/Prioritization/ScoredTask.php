<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * A task after scoring: the normalised criterion values c (0..1), the
 * per-criterion contributions K in points, the score S (0..100) and its rank.
 */
final class ScoredTask
{
    /**
     * @param array<string, float> $criteria      c_j keyed by I, U, D, P
     * @param array<string, float> $contributions K_j = 100 * w_j * c_j
     */
    public function __construct(
        public readonly TaskInput $task,
        public readonly array $criteria,
        public readonly array $contributions,
        public readonly float $score,
        public readonly ?float $daysRemaining,
        public readonly int $rank = 0,
    ) {
    }

    public function withRank(int $rank): self
    {
        return new self($this->task, $this->criteria, $this->contributions, $this->score, $this->daysRemaining, $rank);
    }

    /** Score rounded half-up to one decimal, as displayed (FR4). */
    public function displayScore(): float
    {
        return Rounding::halfUp($this->score, 1);
    }

    public function contribution(string $key): float
    {
        return $this->contributions[$key];
    }

    public function criterion(string $key): float
    {
        return $this->criteria[$key];
    }

    public function isOverdue(): bool
    {
        return $this->daysRemaining !== null && $this->daysRemaining <= 0;
    }
}
