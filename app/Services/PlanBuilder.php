<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Plan my day / week": fills free time with work on tasks.
 *
 *  1. Tasks in ranking order (the report's formula). Then tasks that can only start
 *     later in the period, from their start date, earliest due first.
 *  2. Each task gets its hours of work, minus the time already booked for it.
 *  3. Only inside working hours, around classes and events (with a gap), never in the past.
 *  4. At most H hours of tasks a day: the same H the formula's workload score uses.
 *  5. Only in time that ends before the task is due (an overdue task: as soon as possible).
 *  6. Blocks of 30 min to 2 h, with a short break after each.
 *  7. Pinned blocks (placed by the user) stay; blocks the planner placed are replaced.
 */
class PlanBuilder
{
    public const MIN_BLOCK = 30;  // minutes

    public const MAX_BLOCK = 120;

    public const GAP = 10;        // minutes around events, and after each block

    public const DEFAULT_HOURS = ['start' => '08:00', 'end' => '22:00'];

    public function __construct(private readonly RankingService $ranking) {}

    /** Working hours for Monday..Sunday (null = a day off). */
    public static function hours(User $user): array
    {
        $h = $user->planner_hours;

        return is_array($h) && count($h) === 7 ? array_values($h) : array_fill(0, 7, self::DEFAULT_HOURS);
    }

    /**
     * Plan the given local days (in order). Returns what changed, so it can be undone, and what didn't fit.
     * With $only, books just that task into free time and leaves every other block where it is.
     *
     * @param  list<string>  $days  local dates, "2026-11-13"
     * @return array{added: list<int>, removed: list<int>, short: list<array{taskId: int, title: string, minutes: int, dueAt: ?string}>, minutes: int}
     */
    public function plan(User $user, array $days, ?int $only = null): array
    {
        $tz = $user->timezone;
        $now = $this->ranking->now();
        [$start, $end] = self::period($days, $tz);

        // Planning again: the planner's own blocks still ahead in this period make way.
        $old = $only ? collect() : $user->plannerBlocks()->where('pinned', false)->where('starts_at', '>=', $start->max($now))->where('starts_at', '<', $end)->get();
        $old->each->delete();

        $blocks = $user->plannerBlocks()->active()->get();
        $bookedAhead = $this->bookedAhead($blocks, $now);
        $perDay = [];
        foreach ($blocks as $b) {
            $d = $b->starts_at->timezone($tz)->toDateString();
            $perDay[$d] = ($perDay[$d] ?? 0) + $b->minutes();
        }
        $limit = (int) round($user->weights()->hours_per_day * 60);
        $free = $this->freeTime($user, $days, $this->busy($user, $blocks, $start, $end), $now, $tz);

        $added = [];
        $short = [];
        $minutes = 0;
        $queue = $this->queue($user, $this->ranking->rank($user), $start, $end);
        if ($only) {
            $queue = array_values(array_filter($queue, fn ($q) => $q[0]->id === $only));
        }
        foreach ($queue as [$task, $availableFrom]) {
            $need = (int) round($task->est_hours * 60) - (int) ($bookedAhead[$task->id] ?? 0);
            if ($need <= 0) {
                continue;
            }
            $due = $task->due_at ? CarbonImmutable::instance($task->due_at) : null;
            $deadline = $due && $due > $now ? $due : null; // overdue: no limit, as soon as possible
            foreach ($days as $day) {
                while ($need > 0) {
                    $room = $limit - ($perDay[$day] ?? 0);
                    $slot = $room >= min(self::MIN_BLOCK, $need) ? $this->slot($free[$day], $need, $room, $availableFrom, $deadline) : null;
                    if (! $slot) {
                        break;
                    }
                    [$i, $s, $take] = $slot;
                    $bEnd = $s->addMinutes($take);
                    $added[] = $user->plannerBlocks()->create(['task_id' => $task->id, 'starts_at' => $s->utc(), 'ends_at' => $bEnd->utc(), 'pinned' => false])->id;
                    [$fs, $fe] = $free[$day][$i];
                    // What is left of that free stretch: before the block, and after it plus a short break.
                    $rest = array_values(array_filter([[$fs, $s], [self::up($bEnd->addMinutes(self::GAP)), $fe]], fn ($r) => $r[1] > $r[0]));
                    array_splice($free[$day], $i, 1, $rest);
                    $need -= $take;
                    $minutes += $take;
                    $perDay[$day] = ($perDay[$day] ?? 0) + $take;
                }
                if ($need <= 0) {
                    break;
                }
            }
            if ($need > 0) {
                $short[] = ['taskId' => $task->id, 'title' => $task->title, 'minutes' => $need, 'dueAt' => $task->due_at?->toIso8601ZuluString()];
            }
        }

        return ['added' => $added, 'removed' => $old->pluck('id')->all(), 'short' => $short, 'minutes' => $minutes];
    }

    /**
     * Tasks that still need time booked in this period, in the order they would get it:
     * minutes of work left that no block covers yet, and whether they are due inside the period.
     *
     * @return list<array{taskId: int, title: string, rank: ?int, minutes: int, dueAt: ?string, dueInPeriod: bool}>
     */
    public function unbooked(User $user, array $days, RankedList $list): array
    {
        $now = $this->ranking->now();
        [$start, $end] = self::period($days, $user->timezone);
        $booked = $this->bookedAhead($user->plannerBlocks()->active()->get(), $now);
        $out = [];
        foreach ($this->queue($user, $list, $start, $end) as [$task]) {
            $need = (int) round($task->est_hours * 60) - (int) ($booked[$task->id] ?? 0);
            if ($need > 0) {
                $out[] = [
                    'taskId' => $task->id,
                    'title' => $task->title,
                    'rank' => $list->rankOf($task->id),
                    'minutes' => $need,
                    'dueAt' => $task->due_at?->toIso8601ZuluString(),
                    'dueInPeriod' => $task->due_at !== null && $task->due_at < $end,
                ];
            }
        }

        return $out;
    }

    /** The first and last moment of a run of local days. */
    public static function period(array $days, string $tz): array
    {
        return [CarbonImmutable::parse($days[0], $tz)->startOfDay(), CarbonImmutable::parse(end($days), $tz)->startOfDay()->addDay()];
    }

    /** Minutes booked per task in blocks that are not over yet. */
    private function bookedAhead(Collection $blocks, CarbonImmutable $now): Collection
    {
        return $blocks->filter(fn ($b) => $b->ends_at > $now)->groupBy('task_id')->map(fn ($g) => $g->sum(fn ($b) => $b->minutes()));
    }

    /** The first stretch in a day where a block fits: [index, start, minutes] or null. */
    private function slot(array $stretches, int $need, int $room, ?CarbonImmutable $from, ?CarbonImmutable $deadline): ?array
    {
        foreach ($stretches as $i => [$fs, $fe]) {
            $s = $from ? self::up($fs->max($from)) : $fs;
            $e = $deadline ? $fe->min($deadline) : $fe;
            $len = intdiv(max(0, $e->getTimestamp() - $s->getTimestamp()), 60);
            $take = min($need, $len, self::MAX_BLOCK, $room);
            // A block is at least 30 minutes, unless less than that is all that is left to do.
            if ($take > 0 && $take >= min(self::MIN_BLOCK, $need)) {
                return [$i, $s, $take];
            }
        }

        return null;
    }

    /** Tasks in the order they get time: ranked first (formula order), then ones that start later in the period. */
    private function queue(User $user, RankedList $list, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $queue = array_map(fn ($s) => [$list->tasks[(int) $s->task->id], null], $list->scored);
        $later = $this->ranking->notReady($user)
            ->filter(fn ($r) => $r['kind'] === 'later' && $r['task']->available_from >= $start && $r['task']->available_from < $end)
            ->sortBy(fn ($r) => $r['task']->due_at?->getTimestamp() ?? PHP_INT_MAX)
            ->map(fn ($r) => [$r['task'], CarbonImmutable::instance($r['task']->available_from)]);

        return [...$queue, ...$later->values()->all()];
    }

    /**
     * Time that is taken: classes and events (with a gap either side; all-day ones are
     * reminders and take no time) and blocks that stay (pinned ones, and ones in the past).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function busy(User $user, Collection $blocks, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $busy = [];
        foreach ($user->events()->where('all_day', false)->get() as $e) {
            /** @var Event $e */
            foreach ($e->occurrences($start->utc(), $end->utc(), $user->timezone) as $o) {
                $busy[] = [$o['start']->subMinutes(self::GAP), $o['end']->addMinutes(self::GAP)];
            }
        }
        foreach ($blocks as $b) {
            if ($b->ends_at > $start && $b->starts_at < $end) {
                $busy[] = [$b->starts_at, $b->ends_at->addMinutes(self::GAP)];
            }
        }
        // Habits with a set time keep it, unless already done or skipped that day.
        $tz = $user->timezone;
        foreach (HabitTimes::between($user, $start->timezone($tz), $end->timezone($tz)) as $h) {
            if (! $h['done'] && ! $h['skipped']) {
                $busy[] = [$h['start']->subMinutes(self::GAP), $h['end']->addMinutes(self::GAP)];
            }
        }

        return $busy;
    }

    /**
     * Free stretches per day: working hours, from now on, minus what is taken.
     *
     * @return array<string, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    private function freeTime(User $user, array $days, array $busy, CarbonImmutable $now, string $tz): array
    {
        $hours = self::hours($user);
        $free = [];
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day, $tz);
            $h = $hours[$date->dayOfWeekIso - 1] ?? null;
            $free[$day] = [];
            if (! $h || empty($h['start']) || empty($h['end'])) {
                continue; // a day off
            }
            $s = CarbonImmutable::parse("{$day} {$h['start']}", $tz)->max(self::up($now));
            $e = CarbonImmutable::parse("{$day} {$h['end']}", $tz);
            $stretches = $s < $e ? [[$s, $e]] : [];
            foreach ($busy as [$bs, $be]) {
                $next = [];
                foreach ($stretches as [$fs, $fe]) {
                    if ($be <= $fs || $bs >= $fe) {
                        $next[] = [$fs, $fe];

                        continue;
                    }
                    if ($bs > $fs) {
                        $next[] = [$fs, $bs];
                    }
                    if ($be < $fe) {
                        $next[] = [self::up($be), $fe];
                    }
                }
                $stretches = $next;
            }
            usort($stretches, fn ($a, $b) => $a[0] <=> $b[0]);
            $free[$day] = array_values(array_filter($stretches, fn ($r) => $r[1] > $r[0]));
        }

        return $free;
    }

    /** Rounded up to the next 5 minutes, so blocks start at tidy times. */
    private static function up(CarbonImmutable $t): CarbonImmutable
    {
        $t = $t->setSecond(0)->setMicrosecond(0)->addMinutes($t->second > 0 || $t->microsecond > 0 ? 1 : 0);
        $m = $t->minute % 5;

        return $m ? $t->addMinutes(5 - $m) : $t;
    }
}
