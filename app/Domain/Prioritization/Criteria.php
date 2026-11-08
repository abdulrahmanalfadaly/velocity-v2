<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * The four prioritization criteria (Table 3.2), in a fixed order that is also
 * the order of the stacked contribution bar and the colour slots in the UI.
 */
final class Criteria
{
    public const IMPORTANCE = 'I';
    public const URGENCY = 'U';
    public const DEADLINE = 'D';
    public const PRESSURE = 'P';

    /** @var list<string> */
    public const KEYS = [self::IMPORTANCE, self::URGENCY, self::DEADLINE, self::PRESSURE];

    /** Lower-case names used inside explanation sentences (Algorithm 3.3). */
    public const NAMES = [
        self::IMPORTANCE => 'importance',
        self::URGENCY => 'urgency',
        self::DEADLINE => 'deadline proximity',
        self::PRESSURE => 'workload pressure',
    ];

    /** Title-case labels used in the interface. */
    public const LABELS = [
        self::IMPORTANCE => 'Importance',
        self::URGENCY => 'Urgency',
        self::DEADLINE => 'Deadline proximity',
        self::PRESSURE => 'Workload pressure',
    ];

    /** CSS slug per criterion (colour tokens --c-importance, ...). */
    public const SLUGS = [
        self::IMPORTANCE => 'importance',
        self::URGENCY => 'urgency',
        self::DEADLINE => 'deadline',
        self::PRESSURE => 'pressure',
    ];

    public static function assertKey(string $key): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown criterion '{$key}'.");
        }
    }
}
