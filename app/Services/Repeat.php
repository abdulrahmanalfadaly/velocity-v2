<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Repeat rules for tasks: a unit (day, weekday, week, month, year) and how many
 * of them ("every 2 weeks"). Dates are stepped in the user's own time zone, so a
 * task due at 9:00 stays at 9:00 local time.
 */
final class Repeat
{
    public const UNITS = ['day', 'weekday', 'week', 'month', 'year'];

    /**
     * The next due date after $base. Dates that are already past $now are
     * skipped, so finishing an overdue task never creates an overdue copy.
     */
    public static function next(CarbonImmutable $base, string $unit, int $every, string $timezone, CarbonImmutable $now, ?int $day = null): CarbonImmutable
    {
        $every = max(1, $every);
        $date = $base->timezone($timezone);
        for ($guard = 0; $guard < 5000; $guard++) {
            $date = self::step($date, $unit, $every, $day);
            if ($date->greaterThan($now)) {
                break;
            }
        }

        return $date->utc();
    }

    /** The day of the month a monthly or yearly repeat keeps to (the 31st stays the 31st where the month has one). */
    public static function anchorDay(CarbonImmutable $base, string $unit, string $timezone): ?int
    {
        return in_array($unit, ['month', 'year'], true) ? $base->timezone($timezone)->day : null;
    }

    private static function step(CarbonImmutable $date, string $unit, int $every, ?int $day): CarbonImmutable
    {
        $next = match ($unit) {
            'day' => $date->addDays($every),
            'weekday' => $date->addWeekdays(1),
            'week' => $date->addWeeks($every),
            'month' => $date->addMonthsNoOverflow($every),
            'year' => $date->addYearsNoOverflow($every),
            default => throw new \InvalidArgumentException("Unknown repeat unit: {$unit}"),
        };

        return $day && in_array($unit, ['month', 'year'], true) ? $next->setDay(min($day, $next->daysInMonth)) : $next;
    }

    /** "Every day", "Every weekday", "Every 2 weeks" … */
    public static function label(string $unit, int $every): string
    {
        if ($unit === 'weekday') {
            return 'Every weekday';
        }

        return $every > 1 ? "Every {$every} {$unit}s" : "Every {$unit}";
    }
}
