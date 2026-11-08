<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * Everything the engine needs besides the tasks and the clock: the weights,
 * the deadline horizon h (days at which proximity = 0.5) and the planned
 * task hours per day H (Section 3.5.2).
 */
final class RankingParameters
{
    public const DEFAULT_HORIZON_DAYS = 7.0;
    public const DEFAULT_HOURS_PER_DAY = 4.0;

    public const MIN_HORIZON_DAYS = 1.0;
    public const MAX_HORIZON_DAYS = 30.0;
    public const MIN_HOURS_PER_DAY = 0.5;
    public const MAX_HOURS_PER_DAY = 16.0;

    /** Horizon over which a task without a due date is assessed for workload pressure. */
    public const NO_DUE_DATE_DAYS = 30.0;

    public function __construct(
        public readonly Weights $weights,
        public readonly float $horizonDays = self::DEFAULT_HORIZON_DAYS,
        public readonly float $hoursPerDay = self::DEFAULT_HOURS_PER_DAY,
    ) {
        if ($horizonDays <= 0) {
            throw new \InvalidArgumentException('The deadline horizon must be positive.');
        }
        if ($hoursPerDay <= 0) {
            throw new \InvalidArgumentException('Hours per day must be positive.');
        }
    }

    public static function defaults(): self
    {
        return new self(Weights::defaults());
    }

    public function withWeights(Weights $weights): self
    {
        return new self($weights, $this->horizonDays, $this->hoursPerDay);
    }
}
