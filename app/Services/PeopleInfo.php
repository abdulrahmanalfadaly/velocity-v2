<?php

namespace App\Services;

use App\Models\CatchUp;
use App\Models\Person;
use App\Models\PersonDate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the People screens, Home and the Planner show about people: the next date
 * that comes round, whether a catch-up is due, and a person's full details.
 * $today is the user's own day (local midnight).
 */
class PeopleInfo
{
    /** Dates within this many days count as "needs attention". */
    public const SOON = 7;

    public static function row(Person $p, CarbonImmutable $today): array
    {
        $next = $p->dates
            ->map(fn (PersonDate $d) => ['d' => $d, 'on' => $d->next($today)])
            ->filter(fn ($x) => $x['on'] && $x['on']->diffInDays($today, true) <= 60)
            ->sortBy(fn ($x) => $x['on']->getTimestamp())->first();
        $catch = $p->nextCatchUp($today);
        $date = $next ? self::date($next['d'], $p, $today) : null;
        $late = $catch ? (int) round($catch->diffInDays($today, false)) : null; // > 0: days late

        return [
            'id' => $p->id,
            'name' => $p->name,
            'initials' => $p->initials(),
            'relationship' => $p->relationship,
            'organisation' => $p->organisation,
            'groups' => $p->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
            'date' => $date,
            'catchUp' => $catch ? ['next' => $catch->toDateString(), 'late' => $late] : null,
            'attention' => ($late !== null && $late >= 0) || ($date && $date['days'] <= self::SOON),
            'archived' => $p->archived_at !== null,
        ];
    }

    /** Everything the person's panel shows. */
    public static function detail(Person $p, CarbonImmutable $today, RankedList $list): array
    {
        $catch = $p->nextCatchUp($today);
        $dates = $p->dates->map(fn (PersonDate $d) => self::date($d, $p, $today))
            ->sortBy(fn ($d) => $d['next'] ?? '9999')->values();

        return self::row($p, $today) + [
            'about' => $p->about,
            'contacts' => $p->contacts->map(fn ($c) => ['kind' => $c->kind, 'label' => $c->label, 'value' => $c->value])->values(),
            'dates' => $dates,
            'keepInTouch' => [
                'every' => $p->catch_up_days,
                'next' => $catch?->toDateString(),
                'last' => $p->catchUps->first()?->on->toDateString(),
                'history' => $p->catchUps->take(20)->map(fn (CatchUp $c) => [
                    'id' => $c->id, 'on' => $c->on->toDateString(), 'kind' => $c->kind, 'note' => $c->note,
                ])->values(),
            ],
            'tasks' => $p->tasks->sortBy(fn ($t) => [$t->status === 'done' ? 1 : 0, $list->rankOf($t->id) ?? 9999, $t->title])
                ->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'rank' => $list->rankOf($t->id)])->values(),
            'notes' => $p->notes->sortByDesc('updated_at')->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle()])->values(),
            'projects' => $p->projects->map(fn ($pr) => ['id' => $pr->id, 'name' => $pr->name])->values(),
            'form' => self::form($p),
        ];
    }

    /** The edit dialog's values (and what Undo of an edit sends back). */
    public static function form(Person $p): array
    {
        return [
            'name' => $p->name,
            'relationship' => $p->relationship,
            'organisation' => $p->organisation,
            'catch_up_days' => $p->catch_up_days,
            'contacts' => $p->contacts->map(fn ($c) => ['kind' => $c->kind, 'label' => $c->label, 'value' => $c->value])->values()->all(),
            'group_ids' => $p->groups->pluck('id')->all(),
        ];
    }

    /** A date as lists show it: when it next comes round and how far off that is. */
    public static function date(PersonDate $d, Person $p, CarbonImmutable $today): array
    {
        $next = $d->next($today);

        return [
            'id' => $d->id,
            'personId' => $p->id,
            'name' => $p->name,
            'kind' => $d->kind,
            'label' => $d->label,
            'title' => $d->title($p->name),
            'month' => $d->month,
            'day' => $d->day,
            'year' => $d->year,
            'yearly' => $d->yearly,
            'remindDays' => $d->remind_days,
            'next' => $next?->toDateString(),
            'days' => $next ? (int) round($today->diffInDays($next)) : null,
            'turns' => $d->turns($next),
        ];
    }

    /** Dates coming round in the next n days (today included), soonest first. */
    public static function upcoming(User $user, CarbonImmutable $today, int $days): Collection
    {
        return self::active($user)->with('dates')->get()
            ->flatMap(fn (Person $p) => $p->dates->map(fn ($d) => self::date($d, $p, $today)))
            ->filter(fn ($d) => $d['next'] !== null && $d['days'] < $days)
            ->sortBy(fn ($d) => [$d['next'], $d['name']])->values();
    }

    /** People it is time to catch up with, the longest overdue first. */
    public static function catchUpsDue(User $user, CarbonImmutable $today): Collection
    {
        return self::active($user)->whereNotNull('catch_up_days')->with('catchUps')->get()
            ->map(fn (Person $p) => ['personId' => $p->id, 'name' => $p->name, 'initials' => $p->initials(), 'next' => $p->nextCatchUp($today)])
            ->filter(fn ($x) => $x['next'] <= $today)
            ->sortBy(fn ($x) => $x['next']->getTimestamp())
            ->map(fn ($x) => ['personId' => $x['personId'], 'name' => $x['name'], 'initials' => $x['initials'], 'late' => (int) round($x['next']->diffInDays($today))])
            ->values();
    }

    /** Dates on the Planner between two local days (all-day markers). */
    public static function between(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $out = collect();
        foreach (self::active($user)->with('dates')->get() as $p) {
            foreach ($p->dates as $d) {
                foreach (range($from->year, $to->year) as $y) {
                    if (! $d->yearly && $y !== $d->year) {
                        continue;
                    }
                    $on = $d->in($y, $from->timezone->getName());
                    if ($on >= $from && $on < $to) {
                        $out->push(['key' => "d{$d->id}:{$y}", 'date' => $on->toDateString(), 'title' => $d->title($p->name), 'kind' => $d->kind, 'personId' => $p->id]);
                    }
                }
            }
        }

        return $out->sortBy('date')->values();
    }

    private static function active(User $user)
    {
        return $user->people()->whereNull('archived_at');
    }
}
