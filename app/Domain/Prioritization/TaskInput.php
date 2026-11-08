<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

use DateTimeImmutable;

/**
 * The attributes of one open task that the engine reads. Deliberately free of
 * any web or database dependency so that the engine can be unit-tested with
 * known inputs (Section 3.4.2).
 */
final class TaskInput
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $title,
        public readonly int $importance,
        public readonly int $urgency,
        public readonly ?DateTimeImmutable $dueAt,
        public readonly float $estHours,
        public readonly DateTimeImmutable $createdAt,
    ) {
        if ($importance < 1 || $importance > 5) {
            throw new \InvalidArgumentException('Importance must be between 1 and 5.');
        }
        if ($urgency < 1 || $urgency > 5) {
            throw new \InvalidArgumentException('Urgency must be between 1 and 5.');
        }
        if ($estHours <= 0) {
            throw new \InvalidArgumentException('Estimated duration must be positive.');
        }
    }
}
