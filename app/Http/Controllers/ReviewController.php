<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\HabitStats;
use App\Services\PeopleInfo;
use App\Services\RankingService;
use App\Services\ReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Review: look back over a week or a month, 12 weeks at a glance, and the weekly review (look ahead). */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $review, private readonly RankingService $ranking) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $q = $request->validate(['view' => ['nullable', Rule::in(['week', 'month', 'weeks', 'weekly'])], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $view = $q['view'] ?? 'week';
        $today = $this->review->today($user);
        $day = isset($q['date']) ? CarbonImmutable::parse($q['date'], $user->timezone)->startOfDay() : $today;

        return Inertia::render('review/index', [
            'view' => $view,
            'today' => $today->toDateString(),
            'report' => in_array($view, ['week', 'month'], true) ? $this->review->report($user, $view, $day) : null,
            'weeks' => $view === 'weeks' ? $this->review->weeks($user) : [],
            'weekly' => $view === 'weekly' ? $this->weekly($user, $today) : null,
        ]);
    }

    /** "This week's review is done." */
    public function done(Request $request): RedirectResponse
    {
        $user = $request->user();
        $week = $this->review->today($user)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        DB::table('weekly_reviews')->updateOrInsert(['user_id' => $user->id, 'week_start' => $week], ['done_at' => now()]);

        return back()->with('toast', 'Weekly review done')->with('undo', $this->undo('undo-weekly-review', 0));
    }

    public function undone(Request $request): RedirectResponse
    {
        $user = $request->user();
        $week = $this->review->today($user)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        DB::table('weekly_reviews')->where('user_id', $user->id)->where('week_start', $week)->delete();

        return back()->with('toast', 'Undone');
    }

    /** Looking ahead: what to clear, check and plan before the new week. */
    private function weekly(User $user, CarbonImmutable $today): array
    {
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $next = $monday->addWeek();
        $list = $this->ranking->rank($user);
        $done = DB::table('weekly_reviews')->where('user_id', $user->id)->where('week_start', $monday->toDateString())->value('done_at');

        return [
            'week' => $monday->toDateString(),
            'nextWeek' => $next->toDateString(),
            'doneAt' => $done ? CarbonImmutable::parse($done)->toIso8601ZuluString() : null,
            'inbox' => $user->tasks()->open()->whereNull('project_id')->count(),
            'waiting' => $user->tasks()->where('status', Task::WAITING)->orderBy('updated_at')->limit(20)->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'waitingFor' => $t->waiting_for, 'since' => $t->updated_at?->toIso8601ZuluString()])->values(),
            'deadlines' => $user->tasks()->open()->where('due_at', '>=', $next->utc())->where('due_at', '<', $next->addWeek()->utc())->orderBy('due_at')->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'dueAt' => $t->due_at->toIso8601ZuluString(), 'rank' => $list->rankOf($t->id)])->values(),
            'overdue' => $user->tasks()->open()->where('due_at', '<', $this->ranking->now())->count(),
            'habits' => $user->habits()->whereNull('archived_at')->with('entries')->get()
                ->map(function (Habit $h) use ($today, $monday) {
                    $days = (new HabitStats($h, $today))->span($monday, (int) round($monday->diffInDays($today)) + 1);
                    $states = array_count_values(array_column($days, 'state'));

                    return ['id' => $h->id, 'name' => $h->name, 'missed' => ($states['missed'] ?? 0) + ($states['partial'] ?? 0), 'skipped' => $states['skipped'] ?? 0, 'done' => $states['done'] ?? 0];
                })
                ->filter(fn ($h) => $h['missed'] > 0)->sortByDesc('missed')->values(),
            'catchUps' => PeopleInfo::catchUpsDue($user, $today),
            'dates' => PeopleInfo::upcoming($user, $today, 14),
            'booked' => (int) round(PlannerBlock::where('user_id', $user->id)->where('starts_at', '>=', $next->utc())->where('starts_at', '<', $next->addWeek()->utc())->get()->sum(fn ($b) => $b->minutes())),
        ];
    }
}
