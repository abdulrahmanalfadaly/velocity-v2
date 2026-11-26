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
use App\Services\PlannerOverlaps;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $events = $user->events()->get()->flatMap(fn (Event $e) => array_map(fn ($o) => $this->event($e, $o), $e->occurrences($first->utc(), $end->utc(), $user->timezone, true)))
            ->sortBy('start')->values();
        $blocks = $user->plannerBlocks()->active()->with(['task:id,title,due_at', 'project:id,name'])->where('ends_at', '>', $first->utc())->where('starts_at', '<', $end->utc())
            ->orderBy('starts_at')->get();
        $notReady = $blocks->contains(fn (PlannerBlock $b) => $b->task_id && ! $list->rankOf($b->task_id))
            ? $this->ranking->notReady($user)->keyBy(fn ($r) => $r['task']->id) : collect();
        $blocks = $blocks->map(fn (PlannerBlock $b) => PlannerController::block($b, $list, $notReady))->values();

        return $this->ok([
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
            'events' => $events,
            'booked' => $blocks,
            'habits' => HabitTimes::between($user, $first, $end)->reject(fn ($o) => $o['skipped'])->map(fn ($o) => HabitTimes::present($o))->values(),
            'dates' => PeopleInfo::between($user, $first, $end),
            'markers' => PlannerController::markers($user, $first, $end, $today, false),
            'due' => $user->tasks()->open()->whereBetween('due_at', [$first->utc(), $end->utc()])->orderBy('due_at')->get(['id', 'title', 'due_at'])
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString()])->values(),
        ], meta: ['timezone' => $user->timezone]);
    }

    /** Free time in working hours, around events, habit times and booked blocks: ?date=2026-11-12&span=day|week. */
    public function free(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'span' => ['nullable', Rule::in(['day', 'week'])]]);
        $date = isset($d['date']) ? CarbonImmutable::parse($d['date'], $user->timezone) : $this->today($user);
        $first = ($d['span'] ?? 'day') === 'week' ? $date->startOfWeek(CarbonImmutable::MONDAY) : $date;
        $days = array_map(fn ($i) => $first->addDays($i)->toDateString(), range(0, ($d['span'] ?? 'day') === 'week' ? 6 : 0));

        return $this->ok($this->builder->free($user, $days), meta: ['timezone' => $user->timezone]);
    }

    /**
     * Book time: a task {"task_id": 12, "date": "2026-11-12", "start": "14:00", "minutes": 90}, a whole
     * project {"kind": "project", "project_id": 3, …} or reserved time {"kind": "reserved", "label": "Gym", …}.
     * Answers with what it overlaps.
     */
    public function storeBlock(Request $request): JsonResponse
    {
        $user = $request->user();
        $kind = $request->validate(['kind' => ['nullable', Rule::in(PlannerBlock::KINDS)]])['kind'] ?? 'task';
        $what = match ($kind) {
            'task' => $request->validate(['task_id' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereIn('status', Task::OPEN)->whereNull('deleted_at')]]),
            'project' => $request->validate(['project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)->whereNull('archived_at')->whereNull('deleted_at')]]),
            'reserved' => ['label' => trim($request->validate(['label' => ['required', 'string', 'max:80']])['label'])],
        };
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'start' => ['required', 'date_format:H:i'], 'minutes' => ['required', 'integer', 'min:5', 'max:720']]);
        $start = CarbonImmutable::parse("{$d['date']} {$d['start']}", $user->timezone);
        $block = $user->plannerBlocks()->create(['kind' => $kind, ...$what, 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($d['minutes'])->utc(), 'pinned' => true]);

        return $this->ok($this->blockOut($block), 201);
    }

    /**
     * Move or resize (any of date, start, minutes), lock or unlock ("locked"), or give a task's
     * time to another task ("task_id"). A locked block moves only with {"anyway": true}: 409 otherwise.
     */
    public function updateBlock(Request $request, PlannerBlock $block): JsonResponse
    {
        $user = $request->user();
        $this->own($block, $user);
        $d = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'start' => ['nullable', 'date_format:H:i'], 'minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'locked' => ['nullable', 'boolean'],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereIn('status', Task::OPEN)->whereNull('deleted_at')],
        ]);
        $moves = isset($d['date']) || isset($d['start']) || isset($d['minutes']);
        abort_if($moves && $block->locked && ! $request->boolean('anyway'), 409, 'This block is locked. Send {"anyway": true} to move it anyway.');
        abort_if(isset($d['task_id']) && $block->kind !== 'task', 422, 'Only time booked for a task can change its task.');
        $block->update(array_filter(['locked' => $d['locked'] ?? null, 'task_id' => $d['task_id'] ?? null], fn ($v) => $v !== null));
        if (! $moves) {
            return $this->ok($this->blockOut($block));
        }
        $local = $block->starts_at->timezone($user->timezone);
        $start = CarbonImmutable::parse(($d['date'] ?? $local->toDateString()).' '.($d['start'] ?? $local->format('H:i')), $user->timezone);
        $block->update(['starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($d['minutes'] ?? $block->minutes())->utc(), 'pinned' => true]);
        if ($block->wasChanged(['starts_at', 'ends_at'])) {
            DB::table('planner_moves')->insert(['user_id' => $user->id, 'planner_block_id' => $block->id, 'moved_at' => $this->ranking->now()->utc()]);
        }

        return $this->ok($this->blockOut($block));
    }

    public function destroyBlock(Request $request, PlannerBlock $block): JsonResponse
    {
        $this->own($block, $request->user());
        $block->delete();

        return $this->ok(['deleted' => true]);
    }

    private function blockOut(PlannerBlock $block): array
    {
        $user = request()->user();
        $overlaps = PlannerOverlaps::find($user, CarbonImmutable::instance($block->starts_at), CarbonImmutable::instance($block->ends_at), $block->id);

        return PlannerController::block($block->fresh(['task', 'project']), $this->ranking->rank($user)) + [
            'overlaps' => array_map(fn ($o) => ['title' => $o['title'], 'start' => $o['start']->toIso8601ZuluString(), 'end' => $o['end']->toIso8601ZuluString()], $overlaps),
        ];
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

    /**
     * Move one time an event happens: {"occurrence": "2026-11-19", "date": "2026-11-20", "start": "10:00", "minutes": 90,
     * "scope": "all"|"one"}. A weekly event moves every week, or only that week ("one": a one-off copy takes its
     * place). A locked one needs {"anyway": true}.
     */
    public function moveEvent(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        $m = EventController::applyMove($request, $user, $event);

        return $this->ok($this->eventRecord($event->fresh(), $user->timezone) + ['copy' => $m['copy'] ? $this->eventRecord($m['copy'], $user->timezone) : null]);
    }

    /** Cancel the day an event happens {"date": "2026-11-18"} (it stays, crossed out, and takes no time), or {"cancelled": false} to take it back. */
    public function cancelEvent(Request $request, Event $event): JsonResponse
    {
        $this->own($event, $request->user());
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'cancelled' => ['nullable', 'boolean']]);
        $on = (bool) ($d['cancelled'] ?? true);
        $dates = array_values(array_diff($event->cancelled ?? [], [$d['date']]));
        $event->update(['cancelled' => $on ? [...$dates, $d['date']] : ($dates ?: null)]);

        return $this->ok(['id' => $event->id, 'cancelled' => $event->cancelled ?? []]);
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
            'area' => $e->areaInfo(),
            'location' => $e->location,
            'allDay' => $e->all_day,
            'locked' => $e->locked,
            'cancelled' => $o['cancelled'],
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
