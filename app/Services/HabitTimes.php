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
        $habits = $user->habits()->whereNull('archived_at')->whereNotNull('at_time')->whereNotNull('minutes')->whereNull('per_week')->whereNull('per_month')
            ->with(['entries' => fn ($q) => $q->where('day', '>=', $from->toDateString())->where('day', '<', $to->toDateString()), 'pauses'])
            ->get();

        $out = collect();
        foreach ($habits as $h) {
            $entries = $h->entries->keyBy(fn ($e) => $e->day->toDateString());
            for ($d = $from; $d < $to; $d = $d->addDay()) {
                if (! $h->isDue($d) || $h->pausedOn($d->toDateString())) {
                    continue;
                }
                $e = $entries[$d->toDateString()] ?? null;
                $start = CarbonImmutable::parse($d->toDateString().' '.$h->at_time, $tz);
                $out->push([
                    'habit' => $h,
                    'date' => $d->toDateString(),
                    'start' => $start->utc(),
                    'end' => $start->addMinutes($h->minutes)->utc(),
                    'done' => $e && ! $e->skipped && $h->achieved($e->count),
                    'skipped' => (bool) $e?->skipped,
                    'count' => $e && ! $e->skipped ? $e->count : 0,
                ]);
            }
        }

        return $out->sortBy(fn ($o) => $o['start']->getTimestamp())->values();
    }

    /**
     * Habits with no set time, on the days they are due (the Planner's all-day row): ones
     * with fixed days or "every N days". Weekly or monthly counts and limits have no day.
     *
     * @param  CarbonImmutable  $from  the first day (local midnight)
     * @param  CarbonImmutable  $to  the day after the last (local midnight)
     * @return list<array{key: string, id: int, date: string, title: string, done: bool}>
     */
    public static function untimed(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $habits = $user->habits()->whereNull('archived_at')->whereNull('per_week')->whereNull('per_month')
            ->where(fn ($q) => $q->whereNull('at_time')->orWhereNull('minutes'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhereNotIn('kind', ['limit', 'avoid']))
            ->with(['entries' => fn ($q) => $q->where('day', '>=', $from->toDateString())->where('day', '<', $to->toDateString()), 'pauses'])
            ->orderBy('name')->get();

        $out = [];
        foreach ($habits as $h) {
            $entries = $h->entries->keyBy(fn ($e) => $e->day->toDateString());
            for ($d = $from; $d < $to; $d = $d->addDay()) {
                $day = $d->toDateString();
                if (! $h->isDue($d) || $h->pausedOn($day) || ($h->starts_on && $day < $h->starts_on->toDateString())) {
                    continue;
                }
                $e = $entries[$day] ?? null;
                $out[] = ['key' => "hm{$h->id}:{$day}", 'id' => $h->id, 'date' => $day, 'title' => $h->name, 'done' => $e && ! $e->skipped && $h->achieved($e->count)];
            }
        }

        return $out;
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
