<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\HabitController;
use App\Models\Habit;
use App\Models\User;
use App\Services\HabitStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Habits: how each stands on a day, ticking (or setting a count), skipping, adding. */
class HabitApiController extends ApiController
{
    /** Every habit as it stands today (or on ?date=), with its streak and whether it is due. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $day = $this->day($request, $user);
        $habits = $this->habits($user, $day)->map(fn (Habit $h) => HabitStats::present($h, $day))->values();

        return $this->ok($habits, meta: ['date' => $day->toDateString()]);
    }

    /** {"name": "Read", "schedule": "daily"} (or "days" with days [1–7], or "weekly" with per_week), target, unit, at_time … */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->mergeIfMissing(['schedule' => 'daily', 'target' => 1]);
        $habit = $user->habits()->create(HabitController::fields($request, $user));

        return $this->ok($this->row($user, $habit, $this->today($user)), 201);
    }

    /** Done on a day (today if no date): {"count": 3} for counted habits; no count = all of it; 0 = not done. */
    public function check(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $d = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'count' => ['nullable', 'integer', 'between:0,10000'],
        ], ['date.before_or_equal' => 'That day hasn’t happened yet.']);
        $date = $d['date'] ?? $today->toDateString();
        HabitController::setDay($habit, $date, (int) ($d['count'] ?? $habit->target), false);

        return $this->ok($this->row($user, $habit, CarbonImmutable::parse($date, $user->timezone)));
    }

    /** Skipped on a day (today if no date): it doesn't count against the streak. */
    public function skip(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $date = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->addDays(60)->toDateString()],
        ])['date'] ?? $today->toDateString();
        HabitController::setDay($habit, $date, 0, true);

        return $this->ok($this->row($user, $habit, CarbonImmutable::parse($date, $user->timezone)));
    }

    private function day(Request $request, User $user): CarbonImmutable
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return $date ? CarbonImmutable::parse($date, $user->timezone)->startOfDay() : $this->today($user);
    }

    private function habits(User $user, CarbonImmutable $day)
    {
        return $user->habits()->whereNull('archived_at')->with(['entries' => fn ($q) => $q->where('day', '>=', $day->subDays(400)->toDateString()), 'project'])->get();
    }

    private function row(User $user, Habit $habit, CarbonImmutable $day): array
    {
        return HabitStats::present($this->habits($user, $day)->firstWhere('id', $habit->id) ?? $habit, $day->startOfDay());
    }
}
