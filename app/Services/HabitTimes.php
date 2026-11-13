<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Habits with a set time ("Gym 18:00 for 45 min") on the days they are due: shown
 * on the Planner and Home, and kept free by "Plan my day" unless done or skipped.
 */
class HabitTimes
{
    /**
     * @param  CarbonImmutable  $from  the first day (local midnight)
     * @param  CarbonImmutable  $to  the day after the last (local midnight)
     * @return Collection<int, array{habit: Habit, date: string, start: CarbonImmutable, end: CarbonImmutable, done: bool, skipped: bool, count: int}>
     */
    public static function between(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tz = $user->timezone;
        $habits = $user->habits()->whereNull('archived_at')->whereNotNull('at_time')->whereNotNull('minutes')->whereNull('per_week')
            ->with(['entries' => fn ($q) => $q->where('day', '>=', $from->toDateString())->where('day', '<', $to->toDateString())])
            ->get();

        $out = collect();
        foreach ($habits as $h) {
            $entries = $h->entries->keyBy(fn ($e) => $e->day->toDateString());
            for ($d = $from; $d < $to; $d = $d->addDay()) {
                if (! $h->isDue($d)) {
                    continue;
                }
                $e = $entries[$d->toDateString()] ?? null;
                $start = CarbonImmutable::parse($d->toDateString().' '.$h->at_time, $tz);
                $out->push([
                    'habit' => $h,
                    'date' => $d->toDateString(),
                    'start' => $start->utc(),
                    'end' => $start->addMinutes($h->minutes)->utc(),
                    'done' => $e && ! $e->skipped && $e->count >= $h->target,
                    'skipped' => (bool) $e?->skipped,
                    'count' => $e && ! $e->skipped ? $e->count : 0,
                ]);
            }
        }

        return $out->sortBy(fn ($o) => $o['start']->getTimestamp())->values();
    }

    /** As the Planner grid and Home's plan show it. */
    public static function present(array $o): array
    {
        return [
            'id' => $o['habit']->id,
            'key' => "h{$o['habit']->id}:{$o['date']}",
            'date' => $o['date'],
            'title' => $o['habit']->name,
            'start' => $o['start']->toIso8601ZuluString(),
            'end' => $o['end']->toIso8601ZuluString(),
            'done' => $o['done'],
            'skipped' => $o['skipped'],
            'count' => $o['count'],
            'target' => $o['habit']->target,
        ];
    }
}
