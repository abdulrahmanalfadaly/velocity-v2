<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\EventController;
use App\Http\Controllers\PlannerController;
use App\Models\Event;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Services\HabitTimes;
use App\Services\PeopleInfo;
use App\Services\PlanBuilder;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Planner: classes and events, time booked for tasks, and "Plan my day / week". */
class PlannerApiController extends ApiController
{
    public function __construct(RankingService $ranking, private readonly PlanBuilder $builder)
    {
        parent::__construct($ranking);
    }

    /** Everything on the Planner from one day to another (both included, at most 62 days; default the next 7). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->today($user);
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $first = isset($d['from']) ? CarbonImmutable::parse($d['from'], $user->timezone) : $today;
        $last = isset($d['to']) ? CarbonImmutable::parse($d['to'], $user->timezone) : $first->addDays(6);
        abort_if($first->diffInDays($last) > 62, 422, 'At most 62 days at a time.');
        $end = $last->addDay();
        $list = $this->ranking->rank($user);

        $events = $user->events()->get()->flatMap(fn (Event $e) => array_map(fn ($o) => $this->event($e, $o), $e->occurrences($first->utc(), $end->utc(), $user->timezone)))
            ->sortBy('start')->values();
        $blocks = $user->plannerBlocks()->active()->with('task:id,title,due_at')->where('ends_at', '>', $first->utc())->where('starts_at', '<', $end->utc())
            ->orderBy('starts_at')->get()->map(fn (PlannerBlock $b) => PlannerController::block($b, $list))->values();

        return $this->ok([
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
            'events' => $events,
            'booked' => $blocks,
            'habits' => HabitTimes::between($user, $first, $end)->reject(fn ($o) => $o['skipped'])->map(fn ($o) => HabitTimes::present($o))->values(),
            'dates' => PeopleInfo::between($user, $first, $end),
            'due' => $user->tasks()->open()->whereBetween('due_at', [$first->utc(), $end->utc()])->orderBy('due_at')->get(['id', 'title', 'due_at'])
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString()])->values(),
        ], meta: ['timezone' => $user->timezone]);
    }

    /** "Plan my day / week": free time filled with tasks in rank order. {"span": "day"|"week", "date"?, "task_id"?} */
    public function plan(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'span' => ['required', Rule::in(['day', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'task_id' => ['nullable', 'integer'],
        ]);
        $date = isset($d['date']) ? CarbonImmutable::parse($d['date'], $user->timezone) : $this->today($user);
        $first = $d['span'] === 'week' ? $date->startOfWeek(CarbonImmutable::MONDAY) : $date;
        $days = array_map(fn ($i) => $first->addDays($i)->toDateString(), range(0, $d['span'] === 'week' ? 6 : 0));
        $task = isset($d['task_id']) ? $user->tasks()->whereIn('status', Task::OPEN)->findOrFail($d['task_id']) : null;
        $r = $this->builder->plan($user, $days, $task?->id);

        return $this->ok([
            'bookedMinutes' => $r['minutes'],
            'blocksAdded' => count($r['added']),
            'didNotFit' => array_values($r['short']),
        ]);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        $user = $request->user();
        $event = $user->events()->create(EventController::fields($request, $user));

        return $this->ok($this->eventRecord($event, $user->timezone), 201);
    }

    public function updateEvent(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        $request->merge(array_merge($event->formValues($user->timezone), $request->all()));
        $event->update(EventController::fields($request, $user));

        return $this->ok($this->eventRecord($event->fresh(), $user->timezone));
    }

    /** The whole event, or with {"date": "2026-11-16"} only that week of a weekly one. */
    public function destroyEvent(Request $request, Event $event): JsonResponse
    {
        $this->own($event, $request->user());
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        if ($event->repeat_weekly && $date) {
            $event->update(['skipped' => array_values(array_unique([...($event->skipped ?? []), $date]))]);

            return $this->ok(['id' => $event->id, 'removed' => $date]);
        }
        $event->delete();

        return $this->ok(['id' => $event->id, 'removed' => 'all']);
    }

    /** One time an event happens. */
    private function event(Event $e, array $o): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'calendar' => $e->calendar,
            'location' => $e->location,
            'allDay' => $e->all_day,
            'date' => $o['date'],
            'start' => $o['start']->toIso8601ZuluString(),
            'end' => $o['end']->toIso8601ZuluString(),
        ];
    }

    /** An event as saved, in the same words as the form ("date", "start_time" …). */
    private function eventRecord(Event $e, string $tz): array
    {
        return ['id' => $e->id] + $e->formValues($tz);
    }
}
