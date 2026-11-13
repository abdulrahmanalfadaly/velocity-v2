<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Task;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Planner: a day or a week of classes and events (booking tasks into it comes next). */
class PlannerController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->timezone;
        $q = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', Rule::in(['day', 'week'])]]);
        $today = $this->ranking->now()->timezone($tz)->startOfDay();
        $anchor = isset($q['date']) ? CarbonImmutable::parse($q['date'], $tz)->startOfDay() : $today;
        $view = $q['view'] ?? 'week';
        $first = $view === 'week' ? $anchor->startOfWeek(CarbonImmutable::MONDAY) : $anchor;
        $count = $view === 'week' ? 7 : 1;

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
            'days' => collect(range(0, $count - 1))->map(fn ($i) => $first->addDays($i)->toDateString()),
            'events' => $occ($first, $first->addDays($count))->sortBy(fn ($o) => $o['start']->getTimestamp())->values()
                ->map(fn ($o) => $this->present($o['event'], $o, $tz)),
            'busy' => $busy,
            'calendars' => collect(Event::CALENDARS)->mapWithKeys(fn ($c) => [$c => $events->where('calendar', $c)->count()]),
            'dueTasks' => $user->tasks()->open()->whereBetween('due_at', [$first->utc(), $first->addDays($count)->utc()])
                ->get(['id', 'title', 'due_at'])->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString()])->values(),
        ]);
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
