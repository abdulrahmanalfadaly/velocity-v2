<?php

namespace App\Services;

use App\Models\Habit;
use Carbon\CarbonImmutable;

/**
 * What a habit's days look like, counted in the user's own days.
 *
 * Day states: done, partial (some of a counted target), skipped (neither breaks
 * the streak nor counts), paused (the same), missed (a due day that went by, or
 * a limit gone over), todo (due today, not done yet; a limit or avoid habit's
 * day while it is still going), open (a weekly or monthly habit's day without a
 * run: not a miss), off (not on the schedule), future, before (before the habit
 * existed).
 *
 * Kinds: "do" and "minutes" need the target; "limit" allows up to the target
 * and "avoid" none — a day with nothing logged is kept.
 *
 * Streaks: daily, chosen-day and every-N-days habits count due days done in a
 * row; today only adds once done and never breaks it while it is still going
 * (a limit or avoid day counts once it is over). Weekly and monthly habits
 * count weeks / months in a row with enough runs; skipped and paused days lower
 * what the period asks for.
 */
class HabitStats
{
    /** @var array<string, array{count: int, skipped: bool, note: ?string}> */
    private array $days = [];

    private CarbonImmutable $start;

    public function __construct(private readonly Habit $habit, private readonly CarbonImmutable $today)
    {
        foreach ($habit->entries as $e) {
            $this->days[$e->day->toDateString()] = ['count' => $e->count, 'skipped' => $e->skipped, 'note' => $e->note];
        }
        $created = $habit->created_at ? CarbonImmutable::instance($habit->created_at)->timezone($today->timezone)->startOfDay() : $today;
        $first = $this->days ? CarbonImmutable::parse(min(array_keys($this->days)), $today->timezone) : $created;
        $this->start = $created->min($first)->min($today);
    }

    /** The row on Home, project pages and the Habits lists. */
    public static function present(Habit $habit, CarbonImmutable $todayLocal): array
    {
        return (new self($habit, $todayLocal->startOfDay()))->row();
    }

    public function row(): array
    {
        $h = $this->habit;
        $t = $this->today->toDateString();
        $period = $h->flexible() ? $this->period($this->first($this->today)) : null;
        $pause = $h->pauseOn($t);
        $group = match (true) {
            $pause !== null => 'paused',
            $this->skipped($t) => 'skipped',
            ! $h->less() && ($this->done($t) || ($period && $period['state'] === 'met')) => 'done',
            ! $h->isDue($this->today) => 'off',
            // A limit or something to avoid isn't a job to do: it's kept unless it goes over.
            $h->less() => 'less',
            default => 'todo',
        };

        return [
            'id' => $h->id,
            'name' => $h->name,
            'kind' => $h->kind ?? 'do',
            'detail' => $h->detail,
            'target' => $h->target,
            'unit' => $h->kind === 'minutes' ? 'min' : $h->unit,
            'schedule' => ['days' => $h->days ?? [], 'perWeek' => $h->per_week, 'perMonth' => $h->per_month, 'everyDays' => $h->every_days],
            'label' => $h->scheduleLabel(),
            'today' => $this->count($t),
            'doneToday' => ! $h->less() && $this->done($t),
            // A limit gone over, or the thing to avoid happened.
            'overToday' => $h->less() && ! $this->done($t),
            'skippedToday' => $this->skipped($t),
            'dueToday' => $h->isDue($this->today) && $pause === null,
            'week' => $h->weekly() ? ['done' => $period['done'], 'need' => $period['need']] : null,
            'month' => $h->monthly() ? ['done' => $period['done'], 'need' => $period['need']] : null,
            'group' => $group,
            'pausedSince' => $pause?->from->toDateString(),
            'pausedUntil' => $pause?->until?->toDateString(),
            'streak' => $h->flexible() ? $this->periodStreak() : $this->dayStreak(),
            'streakUnit' => $h->weekly() ? 'week' : ($h->monthly() ? 'month' : 'day'),
            'time' => $h->at_time,
            'minutes' => $h->minutes,
            'project' => $h->project ? ['id' => $h->project->id, 'name' => $h->project->name] : null,
            'archived' => $h->archived_at !== null,
            'status' => $h->status($this->today),
            'startsOn' => $h->starts_on?->toDateString(),
            'endsOn' => $h->ends_on?->toDateString(),
        ];
    }

    /** Everything the habit's panel shows. Load all its entries for a true best streak. */
    public function detail(): array
    {
        $h = $this->habit;
        $monday = $this->today->startOfWeek(CarbonImmutable::MONDAY);
        $notes = array_filter($this->days, fn ($d) => $d['note'] !== null && $d['note'] !== '');
        krsort($notes);

        return $this->row() + [
            'best' => $h->flexible() ? $this->bestPeriodStreak() : $this->bestDayStreak(),
            'rate' => $this->rate(),
            'total' => count(array_filter($this->days, fn ($d) => ! $d['skipped'] && ! $h->less() && $h->achieved($d['count']))),
            // Sixteen weeks, Monday first, ending with this week.
            'history' => $this->span($monday->subWeeks(15), 16 * 7),
            'since' => $this->start->toDateString(),
            'form' => self::form($h),
            'routines' => $h->routines->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'description' => $h->description,
            'why' => $h->why,
            'goals' => $h->goals->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'color' => $g->color])->values(),
            'area' => $h->area ? ['id' => $h->area->id, 'name' => $h->area->name] : null,
            // Notes on check-ins, newest first.
            'notes' => array_map(fn ($date, $d) => ['date' => $date, 'note' => $d['note'], 'count' => $d['count'], 'skipped' => $d['skipped']], array_keys(array_slice($notes, 0, 30, true)), array_slice($notes, 0, 30, true)),
        ];
    }

    /** The habit as the edit dialog shows it (and as Undo of an edit sends it back). */
    public static function form(Habit $h): array
    {
        return [
            'name' => $h->name,
            'kind' => $h->kind ?? 'do',
            'detail' => $h->detail,
            'schedule' => $h->weekly() ? 'weekly' : ($h->monthly() ? 'monthly' : ($h->every_days ? 'every' : (empty($h->days) ? 'daily' : 'days'))),
            'days' => $h->days ?? [],
            'per_week' => $h->per_week,
            'per_month' => $h->per_month,
            'every_days' => $h->every_days,
            'starts_on' => $h->starts_on?->toDateString(),
            'ends_on' => $h->ends_on?->toDateString(),
            'description' => $h->description,
            'why' => $h->why,
            'area_id' => $h->area_id,
            'goal_ids' => $h->goals()->pluck('goals.id')->map(fn ($id) => (int) $id)->all(),
            'target' => $h->target,
            'unit' => $h->unit,
            'at_time' => $h->at_time,
            'minutes' => $h->minutes,
            'project_id' => $h->project_id,
        ];
    }

    /** n days from a date, each with its state. */
    public function span(CarbonImmutable $from, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $from->addDays($i);
            $key = $d->toDateString();
            $out[] = ['date' => $key, 'state' => $this->state($d), 'count' => $this->count($key), 'note' => $this->days[$key]['note'] ?? null];
        }

        return $out;
    }

    public function state(CarbonImmutable $day): string
    {
        $h = $this->habit;
        $d = $day->toDateString();
        if ($this->skipped($d)) {
            return 'skipped';
        }
        if ($day > $this->today) {
            return 'future';
        }
        if ($h->pausedOn($d)) {
            return 'paused';
        }
        // Before its start date or after its end date: not part of the habit.
        if (! $h->activeOn($day)) {
            return $h->starts_on && $d < $h->starts_on->toDateString() ? 'before' : 'off';
        }
        if ($h->less()) {
            // Fewer is better: a day is kept unless it went over; today is still going.
            if ($day < $this->start) {
                return 'before';
            }
            if (! $h->isDue($day)) {
                return 'off';
            }
            if (! $h->achieved($this->count($d))) {
                return 'missed';
            }

            return $day->equalTo($this->today) ? 'todo' : 'done';
        }
        if ($h->achieved($this->count($d))) {
            return 'done';
        }
        if ($this->count($d) > 0) {
            return 'partial';
        }
        if ($day < $this->start) {
            return 'before';
        }
        if (! $h->isDue($day)) {
            return 'off';
        }
        if ($day->equalTo($this->today)) {
            return 'todo';
        }

        return $h->flexible() ? 'open' : 'missed';
    }

    /** Done ÷ days that counted, over the last 30 days; weekly habits: weeks over the last 8; monthly: months over the last 6. */
    public function rate(): array
    {
        $h = $this->habit;
        if ($h->flexible()) {
            $done = $of = 0;
            $first = $this->first($this->today);
            for ($i = 0; $i < ($h->weekly() ? 8 : 6); $i++) {
                $s = $this->period($h->weekly() ? $first->subWeeks($i) : $first->subMonthsNoOverflow($i))['state'];
                if ($s === 'met') {
                    $done++;
                }
                if ($s === 'met' || $s === 'missed') {
                    $of++;
                }
            }

            return ['done' => $done, 'of' => $of, 'unit' => $h->weekly() ? 'weeks' : 'months'];
        }
        $done = $of = 0;
        for ($i = 0; $i < 30; $i++) {
            $s = $this->state($this->today->subDays($i));
            if ($s === 'done') {
                $done++;
            }
            if (in_array($s, ['done', 'partial', 'missed'], true)) {
                $of++;
            }
        }

        return ['done' => $done, 'of' => $of, 'unit' => 'days'];
    }

    private function count(string $d): int
    {
        return ($this->days[$d] ?? null) && ! $this->days[$d]['skipped'] ? $this->days[$d]['count'] : 0;
    }

    private function skipped(string $d): bool
    {
        return ($this->days[$d]['skipped'] ?? false) === true;
    }

    private function done(string $d): bool
    {
        return $this->habit->achieved($this->count($d));
    }

    private function dayStreak(): int
    {
        $n = 0;
        // Today adds once done; a limit or avoid day only once it's over.
        $day = ! $this->habit->less() && $this->done($this->today->toDateString()) ? $this->today : $this->today->subDay();
        for (; $day >= $this->start; $day = $day->subDay()) {
            $s = $this->state($day);
            if ($s === 'done') {
                $n++;
            } elseif (! in_array($s, ['skipped', 'off', 'paused'], true)) {
                break;
            }
        }

        return $n;
    }

    private function bestDayStreak(): int
    {
        $best = $run = 0;
        for ($day = $this->start; $day <= $this->today; $day = $day->addDay()) {
            $s = $this->state($day);
            if ($s === 'done') {
                $best = max($best, ++$run);
            } elseif ($s === 'missed' || $s === 'partial') {
                $run = 0;
            }
        }

        return $best;
    }

    /**
     * Numbers for the last $days days (0: since it began), for the panel's stats:
     * consistency (a partly done day counts as half), bounce-backs (a done day or
     * period right after a missed one), the trend against the same length before,
     * the hardest weekday, and averages.
     */
    public function stats(int $days): array
    {
        $from = ($days ? $this->today->subDays($days - 1) : $this->start)->max($this->start);
        $n = (int) round($from->diffInDays($this->today)) + 1;
        $now = $this->measure($from, $n);
        $before = $days && $from->subDays($n) >= $this->start ? $this->measure($from->subDays($n), $n) : null;

        return $now + [
            'range' => $days,
            'from' => $from->toDateString(),
            'trend' => $before && $before['rate'] !== null && $now['rate'] !== null ? $now['rate'] - $before['rate'] : null,
        ];
    }

    /** The numbers for n days from a day. */
    private function measure(CarbonImmutable $from, int $n): array
    {
        $h = $this->habit;
        $until = $from->addDays($n - 1);
        if ($h->flexible()) {
            $met = $missed = $bounce = 0;
            $wasMissed = false;
            for ($p = $this->first($from); $p <= $until; $p = $this->step($p, 1)) {
                $s = $this->period($p)['state'];
                if ($s === 'met') {
                    $met++;
                    $bounce += $wasMissed ? 1 : 0;
                    $wasMissed = false;
                } elseif ($s === 'missed') {
                    $missed++;
                    $wasMissed = true;
                }
            }
            $of = $met + $missed;

            return ['rate' => $of ? (int) round($met / $of * 100) : null, 'done' => $met, 'partial' => 0, 'of' => $of, 'unit' => $h->weekly() ? 'weeks' : 'months',
                'bounceBacks' => $bounce, 'hardestDay' => null, 'perWeek' => null, 'average' => null];
        }
        $done = $partial = $missed = $bounce = $amount = 0;
        $wasMissed = false;
        $weekdays = array_fill(1, 7, ['ok' => 0.0, 'of' => 0]);
        for ($i = 0; $i < $n; $i++) {
            $day = $from->addDays($i);
            $s = $this->state($day);
            if (! in_array($s, ['done', 'partial', 'missed'], true)) {
                continue; // today while still going, days off, skipped, paused
            }
            $w = $day->dayOfWeekIso;
            $weekdays[$w]['of']++;
            $amount += $this->count($day->toDateString());
            if ($s === 'done') {
                $done++;
                $weekdays[$w]['ok'] += 1;
                $bounce += $wasMissed ? 1 : 0;
                $wasMissed = false;
            } elseif ($s === 'partial') {
                $partial++;
                $weekdays[$w]['ok'] += 0.5;
                $wasMissed = false;
            } else {
                $missed++;
                $wasMissed = true;
            }
        }
        $of = $done + $partial + $missed;
        // The weekday it gets done least (seen at least twice, and not always done).
        $hardest = null;
        $worst = 1.0;
        foreach ($weekdays as $w => $x) {
            if ($x['of'] >= 2 && $worst > $x['ok'] / $x['of']) {
                $worst = $x['ok'] / $x['of'];
                $hardest = $w;
            }
        }

        return [
            'rate' => $of ? (int) round(($done + $partial / 2) / $of * 100) : null,
            'done' => $done,
            'partial' => $partial,
            'of' => $of,
            'unit' => 'days',
            'bounceBacks' => $bounce,
            'hardestDay' => $hardest,
            'perWeek' => $h->less() ? null : round($done / max(1, $n / 7), 1),
            // A counted habit: the amount on an average day (minutes, glasses; a limit: how many).
            'average' => ($h->kind === 'minutes' || $h->target > 1 || $h->kind === 'limit') && $of ? round($amount / $of, 1) : null,
        ];
    }

    /** The first day of the week (Monday) or month a day is in. */
    private function first(CarbonImmutable $day): CarbonImmutable
    {
        return $this->habit->weekly() ? $day->startOfWeek(CarbonImmutable::MONDAY) : $day->startOfMonth();
    }

    /**
     * A week of a weekly habit, or a month of a monthly one: met, missed, open (the
     * current one, not met yet) or neutral (too few days left to ask for anything).
     * Days skipped, paused or before it began lower what the period asks for.
     */
    private function period(CarbonImmutable $first): array
    {
        $h = $this->habit;
        $length = $h->weekly() ? 7 : $first->daysInMonth;
        $per = $h->weekly() ? ($h->per_week ?? 1) : ($h->per_month ?? 1);
        $done = $away = 0;
        for ($i = 0; $i < $length; $i++) {
            $day = $first->addDays($i);
            $d = $day->toDateString();
            if ($this->skipped($d) || $day < $this->start || $h->pausedOn($d)) {
                $away++;
            } elseif ($this->done($d)) {
                $done++;
            }
        }
        // A week asks for no more runs than days are left; a month for its share of the days left (rounded up).
        $need = $h->weekly() ? max(0, min($per, 7 - $away)) : (int) min($per, ceil($per * ($length - $away) / $length));
        $current = $first->equalTo($this->first($this->today));
        $firstPeriod = $first->equalTo($this->first($this->start));
        $state = match (true) {
            $need === 0 => 'neutral',
            $done >= $need => 'met',
            $current => 'open',
            $firstPeriod && $h->weekly() => 'neutral', // the week it started in is never held against it
            default => 'missed',
        };

        return ['done' => $done, 'need' => $h->weekly() ? $per : $need, 'state' => $state];
    }

    private function step(CarbonImmutable $first, int $n): CarbonImmutable
    {
        return $this->habit->weekly() ? $first->addWeeks($n) : $first->addMonthsNoOverflow($n);
    }

    private function periodStreak(): int
    {
        $n = 0;
        $start = $this->first($this->start);
        for ($f = $this->first($this->today); $f >= $start; $f = $this->step($f, -1)) {
            $s = $this->period($f)['state'];
            if ($s === 'met') {
                $n++;
            } elseif ($s === 'missed') {
                break;
            }
        }

        return $n;
    }

    private function bestPeriodStreak(): int
    {
        $best = $run = 0;
        $now = $this->first($this->today);
        for ($f = $this->first($this->start); $f <= $now; $f = $this->step($f, 1)) {
            $s = $this->period($f)['state'];
            if ($s === 'met') {
                $best = max($best, ++$run);
            } elseif ($s === 'missed') {
                $run = 0;
            }
        }

        return $best;
    }
}
