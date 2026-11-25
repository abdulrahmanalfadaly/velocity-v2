<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Milestone;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\HabitTimes;
use App\Services\PeopleInfo;
use App\Services\PlanBuilder;
use App\Services\RankedList;
use App\Services\RankingService;
use App\Support\PlannerSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Planner: a day, a week or a month of classes, events and the time booked for tasks. */
class PlannerController extends Controller
{
    public function __construct(private readonly RankingService $ranking, private readonly PlanBuilder $builder) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->timezone;
        $q = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', Rule::in(['day', 'week', 'month'])]]);
        $today = $this->ranking->now()->timezone($tz)->startOfDay();
        $anchor = isset($q['date']) ? CarbonImmutable::parse($q['date'], $tz)->startOfDay() : $today;
        $view = $q['view'] ?? 'week';
        [$first, $count] = match ($view) {
            'day' => [$anchor, 1],
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), 7],
            // Whole weeks, Monday first, from the week the month starts in to the week it ends in.
            'month' => [
                $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY),
                7 * (int) ceil(($anchor->startOfMonth()->dayOfWeekIso - 1 + $anchor->daysInMonth) / 7),
            ],
        };
        $month = $view === 'month';

        $days = collect(range(0, $count - 1))->map(fn ($i) => $first->addDays($i)->toDateString());
        $from = $first->utc();
        $to = $first->addDays($count)->utc();
        $list = $this->ranking->rank($user);
        $events = $user->events()->get();
        // Cancelled days too: the grid shows them crossed out.
        $occ = fn (CarbonImmutable $from, CarbonImmutable $to) => $events
            ->flatMap(fn (Event $e) => array_map(fn ($o) => ['event' => $e] + $o, $e->occurrences($from->utc(), $to->utc(), $tz, true)));

        // Days in the shown month that have something on (dots in the little calendar).
        $monthStart = $anchor->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $busy = $occ($monthStart, $monthEnd)->pluck('date')
            ->merge($user->tasks()->open()->whereBetween('due_at', [$monthStart->utc(), $monthEnd->utc()])->pluck('due_at')
                ->map(fn ($at) => CarbonImmutable::instance($at)->timezone($tz)->toDateString()))
            ->unique()->values();

        $blocks = $user->plannerBlocks()->active()->with(['task:id,title,due_at', 'project:id,name'])
            ->where('ends_at', '>', $from)->where('starts_at', '<', $to)->orderBy('starts_at')->get();
        // Booked tasks that left the ranking: why each can't be worked on now (looked up only when there are some).
        $notReady = $blocks->contains(fn (PlannerBlock $b) => $b->task_id && ! $list->rankOf($b->task_id))
            ? $this->ranking->notReady($user)->keyBy(fn ($r) => $r['task']->id)
            : collect();

        return Inertia::render('planner/index', [
            'view' => $view,
            'date' => $anchor->toDateString(),
            'today' => $today->toDateString(),
            'days' => $days,
            'events' => $occ($first, $first->addDays($count))->sortBy(fn ($o) => $o['start']->getTimestamp())->values()
                ->map(fn ($o) => $this->present($o['event'], $o, $tz)),
            'blocks' => $blocks->map(fn (PlannerBlock $b) => self::block($b, $list, $notReady)),
            // The month shows no habit times, free time or booking list: too much for small day boxes.
            'habitTimes' => $month ? [] : HabitTimes::between($user, $first, $first->addDays($count))->reject(fn ($o) => $o['skipped'])
                ->map(fn ($o) => HabitTimes::present($o))->values(),
            'personDates' => PeopleInfo::between($user, $first, $first->addDays($count)),
            'markers' => self::markers($user, $first, $first->addDays($count), $today, $month),
            'followUps' => $user->tasks()->open()->whereDate('follow_up_on', '>=', $first->toDateString())->whereDate('follow_up_on', '<=', $first->addDays($count - 1)->toDateString())
                ->get(['id', 'title', 'follow_up_on'])->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'date' => $t->follow_up_on->toDateString()])->values(),
            'unbooked' => $month ? [] : $this->builder->unbooked($user, $days->all(), $list),
            // "Change task" on a block: the ranked tasks, top first.
            'taskChoices' => $month ? [] : array_map(fn ($s) => ['id' => (int) $s->task->id, 'title' => $list->tasks[(int) $s->task->id]->title, 'rank' => $s->rank],
                array_slice($list->scored, 0, 150)),
            'free' => $month ? [] : $this->builder->free($user, $days->all()),
            'hours' => PlanBuilder::hours($user),
            // Hours of task work "Plan" books a day at most: H when Settings → Planner says so; null = fill the working hours.
            'dayLimit' => PlannerSettings::of($user)['cap'] ? (float) $user->weights()->hours_per_day : null,
            // A task dragged in from "Not booked yet" gets at most this many minutes (Settings → Planner → Longest block).
            'longest' => PlannerSettings::of($user)['longest'],
            'busy' => $busy,
            'dueTasks' => $user->tasks()->open()->whereBetween('due_at', [$first->utc(), $first->addDays($count)->utc()])
                ->get(['id', 'title', 'due_at'])->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString()])->values(),
        ]);
    }

    /**
     * A block as the grid and Home show it. A project's block names its top task by the formula ("Next: …").
     * With $notReady (not-ready tasks by id), a task's block says why its task can't be worked on now:
     * waiting, waiting on another task, its project on hold, or booked before the task can start.
     */
    public static function block(PlannerBlock $b, RankedList $list, ?Collection $notReady = null): array
    {
        $next = $b->kind === 'project' ? $list->first(fn (Task $t) => (int) $t->project_id === (int) $b->project_id) : null;
        $why = $b->task_id ? $notReady?->get($b->task_id) : null;
        $blocked = $why && ($why['kind'] !== 'later' || $b->starts_at < $why['task']->available_from) ? ['kind' => $why['kind'], 'why' => $why['why']] : null;

        return [
            'id' => $b->id,
            'kind' => $b->kind,
            'taskId' => $b->task_id,
            'projectId' => $b->project_id,
            'title' => match ($b->kind) {
                'task' => $b->task->title,
                'project' => $b->project->name ?? $b->title(),
                default => $b->title(),
            },
            'start' => $b->starts_at->toIso8601ZuluString(),
            'end' => $b->ends_at->toIso8601ZuluString(),
            'pinned' => $b->pinned,
            'locked' => $b->locked,
            'rank' => $b->task_id ? $list->rankOf($b->task_id) : null,
            'dueAt' => $b->task?->due_at?->toIso8601ZuluString(),
            'next' => $next ? ['id' => $next->id, 'title' => $next->title, 'rank' => $list->rankOf($next->id)] : null,
            'blocked' => $blocked,
        ];
    }

    /**
     * All-day markers for the days shown: project due dates, milestones and goal target
     * dates still ahead of you, habits with no set time on the days they are due (not in
     * the month: too many), and catch-ups. Each can be hidden on the Planner ("Show").
     *
     * @return list<array{key: string, date: string, kind: string, title: string, href: string, done?: bool}>
     */
    public static function markers(User $user, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, bool $month): array
    {
        $first = $from->toDateString();
        $last = $to->subDay()->toDateString();
        $out = [];
        $projects = $user->projects()->whereNull('archived_at')->where('status', '!=', 'completed')->whereDate('due_date', '>=', $first)->whereDate('due_date', '<=', $last)->orderBy('name')->get(['id', 'name', 'due_date']);
        foreach ($projects as $p) {
            $out[] = ['key' => "p{$p->id}", 'date' => $p->due_date->toDateString(), 'kind' => 'project', 'title' => "{$p->name} due", 'href' => "/projects/{$p->id}"];
        }
        $milestones = Milestone::whereIn('project_id', $user->projects()->whereNull('archived_at')->select('id'))->whereNull('done_at')
            ->whereDate('due_date', '>=', $first)->whereDate('due_date', '<=', $last)->orderBy('title')->get(['id', 'project_id', 'title', 'due_date']);
        foreach ($milestones as $m) {
            $out[] = ['key' => "m{$m->id}", 'date' => $m->due_date->toDateString(), 'kind' => 'milestone', 'title' => $m->title, 'href' => "/projects/{$m->project_id}"];
        }
        foreach ($user->goals()->whereNull('archived_at')->whereNull('achieved_at')->whereDate('target_date', '>=', $first)->whereDate('target_date', '<=', $last)->orderBy('title')->get(['id', 'title', 'target_date']) as $g) {
            $out[] = ['key' => "g{$g->id}", 'date' => $g->target_date->toDateString(), 'kind' => 'goal', 'title' => $g->title, 'href' => "/goals?focus=g{$g->id}"];
        }
        if (! $month) {
            foreach (HabitTimes::untimed($user, $from, $to) as $h) {
                $out[] = ['key' => $h['key'], 'date' => $h['date'], 'kind' => 'habit', 'title' => $h['title'], 'href' => "/habits?habit={$h['id']}", 'done' => $h['done']];
            }
        }
        foreach (PeopleInfo::catchUpsBetween($user, $today, $from, $to) as $c) {
            $out[] = ['key' => $c['key'], 'date' => $c['date'], 'kind' => 'catchup', 'title' => $c['title'], 'href' => "/people?person={$c['personId']}"];
        }

        return $out;
    }

    private function present(Event $e, array $o, string $tz): array
    {
        return [
            'id' => $e->id,
            'key' => "{$e->id}:{$o['date']}",
            'date' => $o['date'],
            'start' => $o['start']->toIso8601ZuluString(),
            'end' => $o['end']->toIso8601ZuluString(),
            'title' => $e->title,
            'calendar' => $e->calendar,
            'location' => $e->location,
            'allDay' => $e->all_day,
            'repeats' => $e->repeat_weekly,
            'locked' => $e->locked,
            'cancelled' => $o['cancelled'],
            // The whole event as the edit dialog shows it.
            'form' => $e->formValues($tz),
        ];
    }
}
