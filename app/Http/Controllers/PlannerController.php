<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Services\HabitTimes;
use App\Services\PeopleInfo;
use App\Services\PlanBuilder;
use App\Services\RankedList;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
        $occ = fn (CarbonImmutable $from, CarbonImmutable $to) => $events
            ->flatMap(fn (Event $e) => array_map(fn ($o) => ['event' => $e] + $o, $e->occurrences($from->utc(), $to->utc(), $tz)));

        // Days in the shown month that have something on (dots in the little calendar).
        $monthStart = $anchor->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $busy = $occ($monthStart, $monthEnd)->pluck('date')
            ->merge($user->tasks()->open()->whereBetween('due_at', [$monthStart->utc(), $monthEnd->utc()])->pluck('due_at')
                ->map(fn ($at) => CarbonImmutable::instance($at)->timezone($tz)->toDateString()))
            ->unique()->values();

        return Inertia::render('planner/index', [
            'view' => $view,
            'date' => $anchor->toDateString(),
            'today' => $today->toDateString(),
            'days' => $days,
            'events' => $occ($first, $first->addDays($count))->sortBy(fn ($o) => $o['start']->getTimestamp())->values()
                ->map(fn ($o) => $this->present($o['event'], $o, $tz)),
            'blocks' => $user->plannerBlocks()->active()->with(['task:id,title,due_at', 'project:id,name'])
                ->where('ends_at', '>', $from)->where('starts_at', '<', $to)->orderBy('starts_at')->get()
                ->map(fn (PlannerBlock $b) => self::block($b, $list)),
            // The month shows no habit times, free time or booking list: too much for small day boxes.
            'habitTimes' => $month ? [] : HabitTimes::between($user, $first, $first->addDays($count))->reject(fn ($o) => $o['skipped'])
                ->map(fn ($o) => HabitTimes::present($o))->values(),
            'personDates' => PeopleInfo::between($user, $first, $first->addDays($count)),
            'followUps' => $user->tasks()->open()->whereDate('follow_up_on', '>=', $first->toDateString())->whereDate('follow_up_on', '<=', $first->addDays($count - 1)->toDateString())
                ->get(['id', 'title', 'follow_up_on'])->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'date' => $t->follow_up_on->toDateString()])->values(),
            'unbooked' => $month ? [] : $this->builder->unbooked($user, $days->all(), $list),
            'free' => $month ? [] : $this->builder->free($user, $days->all()),
            'hours' => PlanBuilder::hours($user),
            'dayLimit' => $user->weights()->hours_per_day,
            'busy' => $busy,
            'calendars' => collect(Event::CALENDARS)->mapWithKeys(fn ($c) => [$c => $events->where('calendar', $c)->count()]),
            'dueTasks' => $user->tasks()->open()->whereBetween('due_at', [$first->utc(), $first->addDays($count)->utc()])
                ->get(['id', 'title', 'due_at'])->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString()])->values(),
        ]);
    }

    /** A block as the grid and Home show it. A project's block names its top task by the formula ("Next: …"). */
    public static function block(PlannerBlock $b, RankedList $list): array
    {
        $next = $b->kind === 'project' ? $list->first(fn (Task $t) => (int) $t->project_id === (int) $b->project_id) : null;

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
        ];
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
            // The whole event as the edit dialog shows it.
            'form' => $e->formValues($tz),
        ];
    }
}
