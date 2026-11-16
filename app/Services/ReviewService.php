<?php

namespace App\Services;

use App\Models\CatchUp;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Milestone;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Review: what happened in a week or a month, next to the same stretch of the
 * period before (a week still going is compared with the same days of last week).
 * Facts only: counts and times, no scores or advice. Days are the user's own days;
 * a focus session counts on the day it started.
 */
class ReviewService
{
    public function __construct(private readonly RankingService $ranking) {}

    public function today(User $user): CarbonImmutable
    {
        return $this->ranking->now()->timezone($user->timezone)->startOfDay();
    }

    /**
     * The period around a day: its first day, the day after its last, how far it has
     * got (today included), and the matching stretch of the period before.
     */
    public static function period(string $kind, CarbonImmutable $day, CarbonImmutable $today): array
    {
        $start = match ($kind) {
            'month' => $day->startOfMonth(),
            'day' => $day->startOfDay(),
            default => $day->startOfWeek(CarbonImmutable::MONDAY),
        };
        $end = match ($kind) {
            'month' => $start->addMonth(),
            'day' => $start->addDay(),
            default => $start->addWeek(),
        };
        $until = $end->min($today->addDay());
        $elapsed = (int) round($start->diffInDays($until));
        $prevStart = match ($kind) {
            'month' => $start->subMonth(),
            'day' => $start->subDay(),
            default => $start->subWeek(),
        };
        $prevUntil = $prevStart->addDays($elapsed)->min($start);

        return [
            'kind' => $kind,
            'start' => $start,
            'end' => $end,
            'until' => $until,
            'inProgress' => $until < $end,
            'prevStart' => $prevStart,
            'prevUntil' => $prevUntil,
        ];
    }

    public function report(User $user, string $kind, CarbonImmutable $day): array
    {
        $today = $this->today($user);
        $p = self::period($kind, $day->min($today), $today);
        $now = $this->numbers($user, $p['start'], $p['until'], $today);
        $before = $this->numbers($user, $p['prevStart'], $p['prevUntil'], $today);
        $days = (int) round($p['start']->diffInDays($p['end']));
        $done = $this->done($user, $p['start'], $p['until']);
        $sessions = $this->sessions($user, $p['start'], $p['until']);

        return [
            'kind' => $kind,
            'start' => $p['start']->toDateString(),
            'last' => $p['end']->subDay()->toDateString(),
            'inProgress' => $p['inProgress'],
            'isCurrent' => $p['start'] <= $today && $today < $p['end'],
            'prev' => ['start' => $p['prevStart']->toDateString(), 'last' => $p['prevUntil']->subDay()->toDateString()],
            'now' => $now,
            'before' => $before,
            'days' => collect(range(0, $days - 1))->map(function ($i) use ($p, $today, $done, $sessions, $user) {
                $d = $p['start']->addDays($i);
                $key = $d->toDateString();

                return [
                    'date' => $key,
                    'future' => $d > $today,
                    'done' => $done->filter(fn (Task $t) => $t->completed_at->timezone($user->timezone)->toDateString() === $key)->count(),
                    'focus' => (int) round($sessions->filter(fn ($s) => $s->started_at->timezone($user->timezone)->toDateString() === $key)->sum('seconds') / 60),
                ];
            })->values(),
            'byProject' => $this->byProject($sessions),
            'finished' => $done->sortByDesc('completed_at')->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name,
                'completedAt' => $t->completed_at->toIso8601ZuluString(), 'dueAt' => $t->due_at?->toIso8601ZuluString(),
                'onTime' => $t->due_at ? $t->completed_at <= $t->due_at : null, 'score' => $t->score_at_completion,
            ])->values(),
            'estimates' => $this->estimates($done),
            'habits' => $this->habits($user, $p['start'], $days, $today),
            'highlights' => $kind === 'day' ? [] : $this->highlights($user, $kind, $p, $now, $sessions),
            // What else happened: created, cancelled, reopened, postponed, finished projects, goals, milestones, moved blocks.
            'activity' => $this->activity($user, $p['start'], $p['until'], true),
            'activityBefore' => array_map(fn ($a) => $a['count'], $this->activity($user, $p['prevStart'], $p['prevUntil'], false)),
            'events' => $this->eventTime($user, $p['start'], $p['until']),
        ];
    }

    /**
     * Review → Focus: a week or a month of sessions, when in the day and the week
     * the focus happened (a session is spread over the hours it ran, its pauses
     * taken out evenly), and every session with its intention and note.
     */
    public function focus(User $user, string $range, CarbonImmutable $day): array
    {
        $tz = $user->timezone;
        $today = $this->today($user);
        $from = $range === 'month' ? $day->startOfMonth() : $day->startOfWeek(CarbonImmutable::MONDAY);
        $to = $range === 'month' ? $from->addMonth() : $from->addWeek();
        $sessions = $this->sessions($user, $from, $to)->sortByDesc('started_at')->values();
        $hours = array_fill(0, 24, 0.0);
        $weekdays = array_fill(0, 7, 0.0);
        foreach ($sessions as $s) {
            $t = $s->started_at->timezone($tz);
            $end = $s->ended_at->timezone($tz);
            $scale = $s->seconds / max(1, $end->getTimestamp() - $t->getTimestamp());
            while ($t < $end) {
                $next = $t->startOfHour()->addHour();
                $next = $next < $end ? $next : $end;
                $part = ($next->getTimestamp() - $t->getTimestamp()) * $scale;
                $hours[$t->hour] += $part;
                $weekdays[$t->dayOfWeekIso - 1] += $part;
                $t = $next;
            }
        }
        $minutes = fn (array $a) => array_map(fn ($sec) => (int) round($sec / 60), $a);

        return [
            'range' => $range,
            'start' => $from->toDateString(),
            'last' => $to->subDay()->toDateString(),
            'isCurrent' => $from <= $today && $today < $to,
            'total' => (int) round($sessions->sum('seconds') / 60),
            'count' => $sessions->count(),
            'longest' => (int) round(($sessions->max('seconds') ?? 0) / 60),
            'hours' => $minutes($hours),
            'weekdays' => $minutes($weekdays),
            'sessions' => $sessions->take(300)->map(fn ($s) => [
                'id' => $s->id,
                'taskId' => $s->task_id,
                'task' => $s->task?->title,
                'subject' => $s->subject(),
                'startedAt' => $s->started_at->toIso8601ZuluString(),
                'endedAt' => $s->ended_at->toIso8601ZuluString(),
                'minutes' => (int) round($s->seconds / 60),
                'mode' => $s->mode,
                'round' => $s->round,
                'intention' => $s->intention,
                'note' => $s->note,
            ])->values(),
        ];
    }

    /**
     * What happened in [from, to), each with its items (newest first, up to 50):
     * tasks created (not repeat copies), cancelled (with the reason), reopened,
     * postponed (a due date moved later: "Fri 13 Nov → Mon 16 Nov"), projects
     * completed, goals achieved, milestones reached, and blocks moved.
     *
     * @return array<string, array{count: int, items: list<array>}>
     */
    private function activity(User $user, CarbonImmutable $from, CarbonImmutable $to, bool $items): array
    {
        $tz = $user->timezone;
        $events = TaskEvent::where('user_id', $user->id)->whereIn('type', ['created', 'promoted', 'reopened', 'updated', 'cancelled'])
            ->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())
            ->with(['task' => fn ($q) => $q->withTrashed()->select('id', 'title', 'deleted_at')])
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $day = fn ($at) => CarbonImmutable::parse($at)->timezone($tz)->format('D j M');
        $later = function (TaskEvent $e): bool {
            $b = $e->snapshot['before']['due_at'] ?? null;
            $a = $e->snapshot['after']['due_at'] ?? null;

            return $e->type === 'updated' && $b && $a && strtotime($a) > strtotime($b);
        };
        $list = fn (Collection $rows, callable $item) => ['count' => $rows->count(), 'items' => $items ? $rows->take(50)->map($item)->values()->all() : []];
        $task = fn (?callable $detail = null) => fn (TaskEvent $e) => [
            'id' => $e->task_id, 'title' => $e->task?->title ?? 'Task', 'at' => $e->created_at?->toIso8601ZuluString(),
            'detail' => $detail ? $detail($e) : null, 'href' => $e->task && ! $e->task->trashed() ? "/tasks?task={$e->task_id}" : null,
        ];
        $moves = DB::table('planner_moves')->where('user_id', $user->id)->where('moved_at', '>=', $from->utc())->where('moved_at', '<', $to->utc())->get();

        return [
            'created' => $list($events->filter(fn ($e) => in_array($e->type, ['created', 'promoted'], true) && empty($e->snapshot['repeatOf'])), $task()),
            'cancelled' => $list($events->where('type', 'cancelled'), $task(fn ($e) => $e->snapshot['reason'] ?? null)),
            'reopened' => $list($events->where('type', 'reopened'), $task()),
            'postponed' => $list($events->filter($later), $task(fn ($e) => $day($e->snapshot['before']['due_at']).' → '.$day($e->snapshot['after']['due_at']))),
            'projects' => $list($user->projects()->where('completed_at', '>=', $from->utc())->where('completed_at', '<', $to->utc())->latest('completed_at')->get(),
                fn ($p) => ['id' => $p->id, 'title' => $p->name, 'at' => $p->completed_at->toIso8601ZuluString(), 'detail' => null, 'href' => "/projects/{$p->id}"]),
            'goals' => $list($user->goals()->where('achieved_at', '>=', $from->utc())->where('achieved_at', '<', $to->utc())->latest('achieved_at')->get(),
                fn ($g) => ['id' => $g->id, 'title' => $g->title, 'at' => $g->achieved_at->toIso8601ZuluString(), 'detail' => null, 'href' => "/goals?focus=g{$g->id}"]),
            'milestones' => $list(Milestone::whereIn('project_id', $user->projects()->select('id'))->with('project:id,name')
                ->where('done_at', '>=', $from->utc())->where('done_at', '<', $to->utc())->latest('done_at')->get(),
                fn ($m) => ['id' => $m->id, 'title' => $m->title, 'at' => $m->done_at->toIso8601ZuluString(), 'detail' => $m->project?->name, 'href' => "/projects/{$m->project_id}"]),
            'moved' => ['count' => $moves->count(), 'items' => [], 'blocks' => $moves->unique('planner_block_id')->count()],
        ];
    }

    /** Time in classes and events in [from, to), by calendar (minutes), how many all-day ones, and how many were cancelled. */
    private function eventTime(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = ['class' => 0, 'work' => 0, 'personal' => 0, 'allDay' => 0, 'count' => 0, 'cancelled' => 0];
        foreach ($user->events()->get() as $e) {
            foreach ($e->occurrences($from->utc(), $to->utc(), $user->timezone, true) as $o) {
                if ($o['cancelled']) {
                    $out['cancelled']++;

                    continue;
                }
                if ($e->all_day) {
                    $out['allDay']++;

                    continue;
                }
                $s = $o['start']->max($from);
                $end = $o['end']->min($to);
                if ($end > $s) {
                    $out[$e->calendar] = ($out[$e->calendar] ?? 0) + intdiv($end->getTimestamp() - $s->getTimestamp(), 60);
                    $out['count']++;
                }
            }
        }

        return $out;
    }

    /** The headline numbers for a stretch of days [from, to). */
    public function numbers(User $user, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $done = $this->done($user, $from, $to);
        $withDue = $done->whereNotNull('due_at');
        $habits = $this->habits($user, $from, (int) round($from->diffInDays($to)), $today)->where('weekly', false);
        $catchUps = CatchUp::whereHas('person', fn ($q) => $q->where('user_id', $user->id)->whereNull('deleted_at'))
            ->where('on', '>=', $from->toDateString())->where('on', '<', $to->toDateString())->get(['person_id']);

        return [
            'tasks' => $done->count(),
            'onTime' => $withDue->count() ? (int) round($withDue->filter(fn ($t) => $t->completed_at <= $t->due_at)->count() / $withDue->count() * 100) : null,
            'avgScore' => $done->whereNotNull('score_at_completion')->avg('score_at_completion'),
            'focus' => (int) round($this->sessions($user, $from, $to)->sum('seconds') / 60),
            'booked' => (int) round(PlannerBlock::where('user_id', $user->id)->where('kind', '!=', 'reserved')->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())->get()
                ->sum(fn ($b) => $b->minutes())),
            'habitDays' => ['done' => $habits->sum('done'), 'of' => $habits->sum('of')],
            'catchUps' => $catchUps->count(),
            'people' => $catchUps->unique('person_id')->count(),
            'journal' => $user->notes()->whereNotNull('journal_date')->where('journal_date', '>=', $from->toDateString())->where('journal_date', '<', $to->toDateString())->count(),
            'notes' => $user->notes()->whereNull('journal_date')->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count(),
        ];
    }

    /** Tasks done in [from, to) (deleted ones aside). */
    private function done(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $user->tasks()->where('status', Task::DONE)->where('completed_at', '>=', $from->utc())->where('completed_at', '<', $to->utc())
            ->with('project.goal:id,color')->get();
    }

    /** Finished focus sessions that started in [from, to). */
    private function sessions(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return FocusSession::where('user_id', $user->id)->whereNotNull('ended_at')
            ->where('started_at', '>=', $from->utc())->where('started_at', '<', $to->utc())
            ->with(['task' => fn ($t) => $t->withTrashed()->select('id', 'title', 'project_id'), 'task.project:id,name,goal_id', 'task.project.goal:id,color',
                'project' => fn ($p) => $p->withTrashed()->select('id', 'name', 'goal_id'), 'project.goal:id,color'])
            ->get();
    }

    /** Where focused time went, by project (focus on the project itself, or on one of its tasks), biggest first. */
    private function byProject(Collection $sessions): Collection
    {
        return $sessions->groupBy(fn ($s) => $s->project_id ?? $s->task?->project_id ?? 0)
            ->map(function ($group, $id) {
                $project = $group->first()->project ?? $group->first()->task?->project;

                return [
                    'id' => $id ?: null,
                    'name' => $project?->name ?? 'No project',
                    'color' => $project?->goal?->color ?? 0,
                    'minutes' => (int) round($group->sum('seconds') / 60),
                ];
            })
            ->filter(fn ($r) => $r['minutes'] > 0)->sortByDesc('minutes')->values();
    }

    /**
     * Finished tasks with focused time: the first guess at hours (before any later
     * change, such as "hours left" after a focus session) next to the time focused.
     */
    private function estimates(Collection $done): Collection
    {
        $ids = $done->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $focused = FocusSession::whereIn('task_id', $ids)->whereNotNull('ended_at')->groupBy('task_id')
            ->selectRaw('task_id, sum(seconds) as seconds')->pluck('seconds', 'task_id');
        $firstGuess = [];
        foreach (TaskEvent::whereIn('task_id', $ids)->where('type', 'updated')->orderBy('id')->get() as $e) {
            if (! isset($firstGuess[$e->task_id]) && isset($e->snapshot['before']['est_hours'])) {
                $firstGuess[$e->task_id] = (float) $e->snapshot['before']['est_hours'];
            }
        }

        return $done->filter(fn (Task $t) => ($focused[$t->id] ?? 0) >= 60)
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'guess' => $firstGuess[$t->id] ?? (float) $t->est_hours,
                'focused' => (int) round($focused[$t->id] / 60),
            ])
            ->sortByDesc(fn ($e) => abs($e['focused'] - $e['guess'] * 60))->take(12)->values();
    }

    /** Each habit over the days: done of the days that counted (weekly habits: runs), and its marks. */
    private function habits(User $user, CarbonImmutable $from, int $days, CarbonImmutable $today): Collection
    {
        return $user->habits()->with('entries')->get()
            ->map(function (Habit $h) use ($from, $days, $today) {
                $s = new HabitStats($h, $today);
                $marks = $s->span($from, $days);
                $states = array_column($marks, 'state');
                $done = count(array_filter($states, fn ($x) => $x === 'done'));
                $of = count(array_filter($states, fn ($x) => in_array($x, ['done', 'partial', 'missed'], true)));

                return [
                    'id' => $h->id,
                    'name' => $h->name,
                    'weekly' => $h->weekly(),
                    'perWeek' => $h->per_week,
                    'done' => $done,
                    'of' => $h->weekly() ? 0 : $of,
                    'marks' => $marks,
                    'archived' => $h->archived_at !== null,
                ];
            })
            // Archived habits only where they had something in these days.
            ->filter(fn ($r) => ! $r['archived'] || $r['done'] > 0 || $r['of'] > 0)->values();
    }

    /** At most three facts worth a line of their own. */
    private function highlights(User $user, string $kind, array $p, array $now, Collection $sessions): array
    {
        $out = [];
        if ($now['tasks'] > 0) {
            // The most tasks done in a period, over the last 12 weeks or 6 months.
            $best = true;
            for ($i = 1; $i <= ($kind === 'month' ? 6 : 12); $i++) {
                $s = $kind === 'month' ? $p['start']->subMonths($i) : $p['start']->subWeeks($i);
                if ($this->done($user, $s, $kind === 'month' ? $s->addMonth() : $s->addWeek())->count() >= $now['tasks']) {
                    $best = false;
                    break;
                }
            }
            if ($best && $now['tasks'] >= 3) {
                $out[] = $kind === 'month' ? 'Your most tasks done in a month for half a year.' : 'Your most tasks done in a week for 12 weeks.';
            }
        }
        $longest = $sessions->sortByDesc('seconds')->first();
        if ($longest && $longest->seconds >= 45 * 60) {
            $out[] = 'Longest focus: '.self::hm((int) round($longest->seconds / 60)).($longest->task ? " on “{$longest->task->title}”" : '').'.';
        }
        if ($now['people'] > 0) {
            $out[] = 'In touch with '.$now['people'].($now['people'] === 1 ? ' person.' : ' people.');
        }

        return array_slice($out, 0, 3);
    }

    /** 12 weeks: tasks done (and on time) and focused minutes, oldest first. */
    public function weeks(User $user, int $n = 12): Collection
    {
        $monday = $this->today($user)->startOfWeek(CarbonImmutable::MONDAY);

        return collect(range($n - 1, 0))->map(function ($ago) use ($monday, $user) {
            $start = $monday->subWeeks($ago);
            $done = $this->done($user, $start, $start->addWeek());
            $withDue = $done->whereNotNull('due_at');

            return [
                'start' => $start->toDateString(),
                'done' => $done->count(),
                'onTime' => $withDue->filter(fn ($t) => $t->completed_at <= $t->due_at)->count(),
                'withDue' => $withDue->count(),
                'focus' => (int) round($this->sessions($user, $start, $start->addWeek())->sum('seconds') / 60),
            ];
        })->values();
    }

    public static function hm(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h ? ($m ? "{$h} h {$m} min" : "{$h} h") : "{$m} min";
    }
}
