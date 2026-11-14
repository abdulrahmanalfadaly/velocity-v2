<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Repeat rules for tasks: a unit (day, weekday, week, month, year) and how many
 * of them ("every 2 weeks"); a weekly one can name its weekdays ("every Mon and
 * Thu"). Dates are stepped in the user's own time zone, so a task due at 9:00
 * stays at 9:00 local time.
 */
final class Repeat
{
    public const UNITS = ['day', 'weekday', 'week', 'month', 'year'];

    private const NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * The next due date after $base. Dates that are already past $now are
     * skipped, so finishing an overdue task never creates an overdue copy.
     *
     * @param  list<int>|null  $days  ISO weekdays for a weekly repeat
     */
    public static function next(CarbonImmutable $base, string $unit, int $every, string $timezone, CarbonImmutable $now, ?int $day = null, ?array $days = null): CarbonImmutable
    {
        $every = max(1, $every);
        $date = $base->timezone($timezone);
        $start = $date->startOfWeek(CarbonImmutable::MONDAY);
        for ($guard = 0; $guard < 5000; $guard++) {
            $date = $unit === 'week' && $days ? self::nextWeekday($date, $days, $every, $start) : self::step($date, $unit, $every, $day);
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

    /** The first of the weekdays after $date, in a week that is a whole number of $every weeks from the first one. */
    private static function nextWeekday(CarbonImmutable $date, array $days, int $every, CarbonImmutable $start): CarbonImmutable
    {
        $d = $date;
        for ($i = 0; $i < 7 * $every + 7; $i++) {
            $d = $d->addDay();
            $weeks = intdiv((int) round($start->diffInDays($d->startOfWeek(CarbonImmutable::MONDAY))), 7);
            if (in_array($d->dayOfWeekIso, $days, true) && $weeks % $every === 0) {
                return $d;
            }
        }

        return $date->addWeeks($every);
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

    /** "Every day", "Every weekday", "Every 2 weeks", "Every Mon, Thu", "Every 2 weeks on Mon, Thu" … */
    public static function label(string $unit, int $every, ?array $days = null): string
    {
        if ($unit === 'weekday') {
            return 'Every weekday';
        }
        if ($unit === 'week' && $days) {
            sort($days);
            $names = implode(', ', array_map(fn ($d) => self::NAMES[$d], $days));

            return $every > 1 ? "Every {$every} weeks on {$names}" : "Every {$names}";
        }

        return $every > 1 ? "Every {$every} {$unit}s" : "Every {$unit}";
    }
}
