<?php

namespace App\Services;

use App\Models\Habit;
use Carbon\CarbonImmutable;

/**
 * What a habit's days look like, counted in the user's own days.
 *
 * Day states: done, partial (some of a counted target), skipped (neither breaks
 * the streak nor counts), missed (a due day that went by), todo (due today, not
 * done yet), open (a weekly habit's day without a run: not a miss), off (not on
 * the schedule), future, before (before the habit existed).
 *
 * Streaks: daily and chosen-day habits count due days done in a row; today only
 * adds once done and never breaks it while it is still going. Weekly habits
 * ("3 times a week") count weeks in a row with enough runs; skipped days lower
 * the week's need when fewer days are left than it asks for.
 */
class HabitStats
{
    /** @var array<string, array{count: int, skipped: bool}> */
    private array $days = [];

    private CarbonImmutable $start;

    public function __construct(private readonly Habit $habit, private readonly CarbonImmutable $today)
    {
        foreach ($habit->entries as $e) {
            $this->days[$e->day->toDateString()] = ['count' => $e->count, 'skipped' => $e->skipped];
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
        $week = $h->weekly() ? $this->week($this->today->startOfWeek(CarbonImmutable::MONDAY)) : null;
        $group = match (true) {
            $this->skipped($t) => 'skipped',
            $this->done($t), $week && $week['state'] === 'met' => 'done',
            ! $h->isDue($this->today) => 'off',
            default => 'todo',
        };

        return [
            'id' => $h->id,
            'name' => $h->name,
            'detail' => $h->detail,
            'target' => $h->target,
            'unit' => $h->unit,
            'schedule' => ['days' => $h->days ?? [], 'perWeek' => $h->per_week],
            'label' => $h->scheduleLabel(),
            'today' => $this->count($t),
            'doneToday' => $this->done($t),
            'skippedToday' => $this->skipped($t),
            'dueToday' => $h->isDue($this->today),
            'week' => $week ? ['done' => $week['done'], 'need' => $week['need']] : null,
            'group' => $group,
            'streak' => $h->weekly() ? $this->weekStreak() : $this->dayStreak(),
            'streakUnit' => $h->weekly() ? 'week' : 'day',
            'time' => $h->at_time,
            'minutes' => $h->minutes,
            'project' => $h->project ? ['id' => $h->project->id, 'name' => $h->project->name] : null,
            'archived' => $h->archived_at !== null,
        ];
    }

    /** Everything the habit's panel shows. Load all its entries for a true best streak. */
    public function detail(): array
    {
        $h = $this->habit;
        $monday = $this->today->startOfWeek(CarbonImmutable::MONDAY);

        return $this->row() + [
            'best' => $h->weekly() ? $this->bestWeekStreak() : $this->bestDayStreak(),
            'rate' => $this->rate(),
            'total' => count(array_filter($this->days, fn ($d) => ! $d['skipped'] && $d['count'] >= $h->target)),
            // Sixteen weeks, Monday first, ending with this week.
            'history' => $this->span($monday->subWeeks(15), 16 * 7),
            'since' => $this->start->toDateString(),
            'form' => self::form($h),
            'routines' => $h->routines->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
        ];
    }

    /** The habit as the edit dialog shows it (and as Undo of an edit sends it back). */
    public static function form(Habit $h): array
    {
        return [
            'name' => $h->name,
            'detail' => $h->detail,
            'schedule' => $h->weekly() ? 'weekly' : (empty($h->days) ? 'daily' : 'days'),
            'days' => $h->days ?? [],
            'per_week' => $h->per_week,
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
            $out[] = ['date' => $d->toDateString(), 'state' => $this->state($d), 'count' => $this->count($d->toDateString())];
        }

        return $out;
    }

    public function state(CarbonImmutable $day): string
    {
        $d = $day->toDateString();
        if ($this->skipped($d)) {
            return 'skipped';
        }
        if ($day > $this->today) {
            return 'future';
        }
        if ($this->count($d) >= $this->habit->target) {
            return 'done';
        }
        if ($this->count($d) > 0) {
            return 'partial';
        }
        if ($day < $this->start) {
            return 'before';
        }
        if (! $this->habit->isDue($day)) {
            return 'off';
        }
        if ($day->equalTo($this->today)) {
            return 'todo';
        }

        return $this->habit->weekly() ? 'open' : 'missed';
    }

    /** Done (counted days) ÷ days that counted, over the last 30 days; weekly habits: weeks over the last 8. */
    public function rate(): array
    {
        if ($this->habit->weekly()) {
            $done = $of = 0;
            $monday = $this->today->startOfWeek(CarbonImmutable::MONDAY);
            for ($i = 0; $i < 8; $i++) {
                $s = $this->week($monday->subWeeks($i))['state'];
                if ($s === 'met') {
                    $done++;
                }
                if ($s === 'met' || $s === 'missed') {
                    $of++;
                }
            }

            return ['done' => $done, 'of' => $of, 'unit' => 'weeks'];
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
        return $this->count($d) >= $this->habit->target;
    }

    private function dayStreak(): int
    {
        $n = 0;
        $day = $this->done($this->today->toDateString()) ? $this->today : $this->today->subDay();
        for (; $day >= $this->start; $day = $day->subDay()) {
            $s = $this->state($day);
            if ($s === 'done') {
                $n++;
            } elseif ($s !== 'skipped' && $s !== 'off') {
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

    /** A week of a weekly habit: met, missed, open (this week, not met yet) or neutral (too few days to ask for anything). */
    private function week(CarbonImmutable $monday): array
    {
        $done = $away = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->addDays($i);
            $d = $day->toDateString();
            if ($this->skipped($d) || $day < $this->start) {
                $away++;
            } elseif ($this->done($d)) {
                $done++;
            }
        }
        $need = max(0, min($this->habit->per_week ?? 1, 7 - $away));
        $current = $monday->equalTo($this->today->startOfWeek(CarbonImmutable::MONDAY));
        $firstWeek = $monday->equalTo($this->start->startOfWeek(CarbonImmutable::MONDAY));
        $state = match (true) {
            $need === 0 => 'neutral',
            $done >= $need => 'met',
            $current => 'open',
            $firstWeek => 'neutral', // the week it started in is never held against it
            default => 'missed',
        };

        return ['done' => $done, 'need' => $this->habit->per_week ?? 1, 'state' => $state];
    }

    private function weekStreak(): int
    {
        $n = 0;
        $firstMonday = $this->start->startOfWeek(CarbonImmutable::MONDAY);
        for ($m = $this->today->startOfWeek(CarbonImmutable::MONDAY); $m >= $firstMonday; $m = $m->subWeek()) {
            $s = $this->week($m)['state'];
            if ($s === 'met') {
                $n++;
            } elseif ($s === 'missed') {
                break;
            }
        }

        return $n;
    }

    private function bestWeekStreak(): int
    {
        $best = $run = 0;
        $now = $this->today->startOfWeek(CarbonImmutable::MONDAY);
        for ($m = $this->start->startOfWeek(CarbonImmutable::MONDAY); $m <= $now; $m = $m->addWeek()) {
            $s = $this->week($m)['state'];
            if ($s === 'met') {
                $best = max($best, ++$run);
            } elseif ($s === 'missed') {
                $run = 0;
            }
        }

        return $best;
    }
}
