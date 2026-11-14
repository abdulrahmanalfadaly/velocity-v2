<?php

namespace App\Http\Controllers\Api;

use App\Actions\FocusActions;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Models\Event;
use App\Models\PlannerBlock;
use App\Services\HabitStats;
use App\Services\PeopleInfo;
use App\Services\ReviewService;
use App\Support\ApiAccess;
use App\Support\ApiPresent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Who is calling, today at a glance, search across everything, and Review. */
class OverviewController extends ApiController
{
    /** No key needed: is Velocity there? */
    public function health(): JsonResponse
    {
        return $this->ok(['status' => 'ok', 'version' => 'v1']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return $this->ok([
            'name' => $user->name,
            'timezone' => $user->timezone,
            'today' => $this->today($user)->toDateString(),
            'key' => $token->name,
            'access' => $token->abilities,
            'accessText' => ApiAccess::summary($token->abilities),
            'expiresAt' => $token->expires_at?->toIso8601ZuluString(),
        ]);
    }

    /**
     * Today at a glance. Each part needs its area's read access; a part the key
     * can't read is null (an empty list means there is nothing).
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->today($user);
        $tomorrow = $today->addDay();
        $now = $this->ranking->now();
        $list = $this->can($request, 'tasks:read') || $this->can($request, 'planner:read') ? $this->ranking->rank($user) : null;

        $tasks = null;
        if ($this->can($request, 'tasks:read')) {
            $ranked = collect($list->scored)->map(fn ($s) => $list->tasks[(int) $s->task->id]);
            $tasks = [
                'top' => $ranked->take(5)->map(fn ($t) => ApiPresent::task($t, $list))->values(),
                'ranked' => count($list),
                'overdue' => $ranked->filter(fn ($t) => $t->due_at && $t->due_at < $now)->map(fn ($t) => ApiPresent::task($t, $list))->values(),
                'dueToday' => $ranked->filter(fn ($t) => $t->due_at && $t->due_at >= $now && $t->due_at < $tomorrow->utc())->map(fn ($t) => ApiPresent::task($t, $list))->values(),
                'focus' => FocusActions::running($user)?->present(),
            ];
        }

        $plan = null;
        if ($this->can($request, 'planner:read')) {
            $events = $user->events()->get()->flatMap(fn (Event $e) => array_map(fn ($o) => [
                'id' => $e->id, 'title' => $e->title, 'calendar' => $e->calendar, 'location' => $e->location, 'allDay' => $e->all_day,
                'start' => $o['start']->toIso8601ZuluString(), 'end' => $o['end']->toIso8601ZuluString(),
            ], $e->occurrences($today->utc(), $tomorrow->utc(), $user->timezone)))->sortBy('start')->values();
            $blocks = $user->plannerBlocks()->active()->with('task:id,title,due_at')->where('ends_at', '>', $today->utc())->where('starts_at', '<', $tomorrow->utc())
                ->orderBy('starts_at')->get();
            $plan = [
                'events' => $events,
                'booked' => $blocks->map(fn (PlannerBlock $b) => PlannerController::block($b, $list))->values(),
                'bookedMinutes' => $blocks->sum(fn (PlannerBlock $b) => $b->minutes()),
            ];
        }

        $habits = null;
        if ($this->can($request, 'habits:read')) {
            $habits = $user->habits()->whereNull('archived_at')->with(['entries' => fn ($q) => $q->where('day', '>=', $today->subDays(400)->toDateString()), 'project'])->get()
                ->map(fn ($h) => HabitStats::present($h, $today))->filter(fn ($h) => $h['dueToday'])->values();
        }

        $people = $this->can($request, 'people:read')
            ? ['dates' => PeopleInfo::upcoming($user, $today, PeopleInfo::SOON), 'catchUps' => PeopleInfo::catchUpsDue($user, $today)]
            : null;

        $notes = null;
        if ($this->can($request, 'notes:read')) {
            $journal = $user->notes()->whereDate('journal_date', $today->toDateString())->first();
            $notes = ['journal' => $journal ? ['id' => $journal->id, 'preview' => $journal->preview()] : null];
        }

        return $this->ok([
            'date' => $today->toDateString(),
            'timezone' => $user->timezone,
            'tasks' => $tasks,
            'plan' => $plan,
            'habits' => $habits,
            'people' => $people,
            'notes' => $notes,
        ]);
    }

    /** Everything whose name (or a note's words) contains q, grouped by kind; only the areas the key can read. */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $area = ['task' => 'tasks', 'project' => 'projects', 'goal' => 'projects', 'note' => 'notes', 'habit' => 'habits', 'person' => 'people', 'event' => 'planner'];
        $groups = collect(app(SearchController::class)->find($user, $q, $this->ranking->rank($user), 3))
            ->map(fn ($g) => ['label' => $g['label'], 'items' => collect($g['items'])->filter(fn ($i) => $this->can($request, ($area[$i['kind']] ?? 'none').':read'))->values()])
            ->filter(fn ($g) => $g['items']->isNotEmpty())->values();

        return $this->ok($groups);
    }

    /** The week's or month's numbers, as on Review: {"period": "week"|"month", "date"?}. */
    public function review(Request $request, ReviewService $review): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['period' => ['nullable', Rule::in(['week', 'month'])], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $today = $review->today($user);
        $day = isset($d['date']) ? CarbonImmutable::parse($d['date'], $user->timezone)->startOfDay() : $today;

        return $this->ok($review->report($user, $d['period'] ?? 'week', $day));
    }

    /** The weekly review's checklist for this week. */
    public function weekly(Request $request, ReviewService $review): JsonResponse
    {
        $user = $request->user();

        return $this->ok(app(ReviewController::class)->weekly($user, $review->today($user)));
    }
}
