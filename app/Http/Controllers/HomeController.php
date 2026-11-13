<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Services\HabitStats;
use App\Services\HabitTimes;
use App\Services\RankingService;
use App\Services\TaskPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Home answers one question: what should I do now? */
class HomeController extends Controller
{
    public function __invoke(Request $request, RankingService $ranking): Response
    {
        $user = $request->user();
        $list = $ranking->rank($user);
        $all = $list->toArray();
        $now = $ranking->now();
        $local = $now->timezone($user->timezone);
        $today = $local->startOfDay();

        $done7 = $user->tasks()->where('status', Task::DONE)->where('completed_at', '>=', $today->subDays(6)->utc())->get();
        $week = collect(range(6, 0))->map(function ($ago) use ($today, $done7, $user) {
            $day = $today->subDays($ago);

            return ['day' => $day->toDateString(), 'count' => $done7->filter(fn ($t) => $t->completed_at->timezone($user->timezone)->isSameDay($day))->count()];
        });
        $withDue = $done7->whereNotNull('due_at');

        // Today's plan: classes and events, and the time booked for tasks, in time order.
        $tz = $user->timezone;
        $from = $today->utc();
        $to = $today->addDay()->utc();
        $events = $user->events()->get()->flatMap(fn (Event $e) => array_map(fn ($o) => [
            'kind' => 'event', 'key' => "e{$e->id}:{$o['date']}", 'title' => $e->title, 'calendar' => $e->calendar, 'allDay' => $e->all_day, 'location' => $e->location,
            'start' => $o['start']->toIso8601ZuluString(), 'end' => $o['end']->toIso8601ZuluString(),
        ], $e->occurrences($from, $to, $tz)));
        $habits = $user->habits()->whereNull('archived_at')->with(['entries' => fn ($q) => $q->where('day', '>=', $today->subDays(400)->toDateString()), 'project'])->get()
            ->map(fn ($h) => HabitStats::present($h, $today));
        $groups = $habits->pluck('group', 'id');
        $blocks = $user->plannerBlocks()->active()->with('task:id,title,due_at')->where('ends_at', '>', $from)->where('starts_at', '<', $to)->orderBy('starts_at')->get();

        return Inertia::render('home', [
            'top' => array_slice($all, 0, 3),
            'total' => count($all),
            'overdue' => count(array_filter($all, fn ($t) => $t['days'] !== null && $t['days'] <= 0)),
            'dueSoon' => count(array_filter($all, fn ($t) => $t['dueAt'] && $t['days'] > 0 && $t['days'] <= 2)),
            'plan' => [
                'items' => $events->concat($blocks->map(fn (PlannerBlock $b) => ['kind' => 'block', 'key' => "b{$b->id}"] + PlannerController::block($b, $list)))
                    ->concat(HabitTimes::between($user, $today, $today->addDay())->reject(fn ($o) => $o['skipped'])->map(fn ($o) => ['kind' => 'habit'] + HabitTimes::present($o)))
                    ->sortBy(fn ($i) => ($i['kind'] === 'event' && $i['allDay'] ? '0' : '1').$i['start'])->values(),
                'booked' => $blocks->sum(fn (PlannerBlock $b) => $b->minutes()),
                'limit' => $user->weights()->hours_per_day,
            ],
            // Deadlines in the next seven days, in time order.
            'upcoming' => collect($all)->filter(fn ($t) => $t['dueAt'] && $t['days'] <= 7)->sortBy('dueAt')->take(7)->values(),
            'habits' => $habits->filter(fn ($h) => $h['dueToday'])->values(),
            // Routines with something on today: "Morning 2 / 3".
            'routines' => $user->routines()->with('habits:id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'done' => $r->habits->filter(fn ($h) => ($groups[$h->id] ?? null) === 'done')->count(),
                'of' => $r->habits->filter(fn ($h) => in_array($groups[$h->id] ?? 'off', ['todo', 'done'], true))->count(),
            ])->filter(fn ($r) => $r['of'] > 0)->values(),
            'attention' => [
                'overdue' => collect($all)->filter(fn ($t) => $t['days'] !== null && $t['days'] <= 0)->values(),
                'notReady' => $ranking->notReady($user)->map(fn ($r) => TaskPresenter::notReady($r))->values(),
                'milestones' => $user->projects()->whereNull('archived_at')->with(['milestones' => fn ($q) => $q->whereNull('done_at')->whereBetween('due_date', [$today->toDateString(), $today->addDays(3)->toDateString()])])->get()
                    ->flatMap(fn ($p) => $p->milestones->map(fn ($m) => ['title' => $m->title, 'project' => $p->name, 'projectId' => $p->id, 'dueDate' => $m->due_date->toDateString()]))->values(),
            ],
            'week' => [
                'days' => $week,
                'done' => $done7->count(),
                'onTime' => $withDue->count() ? (int) round($withDue->filter(fn ($t) => $t->completed_at <= $t->due_at)->count() / $withDue->count() * 100) : null,
                'avgScore' => $done7->whereNotNull('score_at_completion')->avg('score_at_completion'),
            ],
        ]);
    }
}
