<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a stretch of time overlaps on the Planner: classes and events (any
 * calendar, shown or hidden; all-day ones take no time), booked blocks, and
 * habit times not yet done or skipped. Only a warning: nothing is moved.
 */
class PlannerOverlaps
{
    /**
     * @return list<array{title: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function find(User $user, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptBlock = null, ?int $exceptEvent = null): array
    {
        $out = [];
        $hit = fn (CarbonImmutable $s, CarbonImmutable $e) => $s < $end && $e > $start;
        foreach ($user->events()->where('all_day', false)->when($exceptEvent, fn ($q) => $q->whereKeyNot($exceptEvent))->get() as $event) {
            /** @var Event $event */
            foreach ($event->occurrences($start->subDay()->utc(), $end->addDay()->utc(), $user->timezone) as $o) {
                if ($hit($o['start'], $o['end'])) {
                    $out[] = ['title' => $event->title, 'start' => $o['start'], 'end' => $o['end']];
                }
            }
        }
        foreach ($user->plannerBlocks()->active()->with('task:id,title')->when($exceptBlock, fn ($q) => $q->whereKeyNot($exceptBlock))
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->get() as $b) {
            $out[] = ['title' => $b->task?->title ?? $b->title(), 'start' => CarbonImmutable::instance($b->starts_at), 'end' => CarbonImmutable::instance($b->ends_at)];
        }
        $tz = $user->timezone;
        foreach (HabitTimes::between($user, $start->timezone($tz)->startOfDay(), $end->timezone($tz)->startOfDay()->addDay()) as $h) {
            if (! $h['done'] && ! $h['skipped'] && $hit($h['start'], $h['end'])) {
                $out[] = ['title' => $h['habit']->name, 'start' => $h['start'], 'end' => $h['end']];
            }
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /** " · overlaps Data Mining lecture (9:00–11:00 am)", or "" when nothing does. */
    public static function note(User $user, array $overlaps): string
    {
        if (! $overlaps) {
            return '';
        }
        $first = $overlaps[0];
        $tz = $user->timezone;
        $s = $first['start']->timezone($tz);
        $e = $first['end']->timezone($tz);
        $range = $s->format('a') === $e->format('a') ? $s->format('g:i').'–'.$e->format('g:i a') : $s->format('g:i a').'–'.$e->format('g:i a');
        $more = count($overlaps) > 1 ? ' and '.(count($overlaps) - 1).' more' : '';

        return " · overlaps {$first['title']} ({$range}){$more}";
    }
}
