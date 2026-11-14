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

    /** Everything about one habit: streak, best, rate, 16 weeks of days, notes on check-ins. */
    public function show(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);

        return $this->ok((new HabitStats($habit->load(['entries', 'project', 'routines', 'pauses']), $this->today($user)))->detail());
    }

    /** Change any of the habit's fields (the rest stay as they are). */
    public function update(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $request->merge(array_merge(HabitStats::form($habit), $request->all()));
        $habit->update(HabitController::fields($request, $user));

        return $this->ok($this->row($user, $habit->fresh(), $this->today($user)));
    }

    /** A day set exactly: {"date": "2026-11-10", "count": 1, "skipped"?: false, "note"?: "Ran 5 km"}. */
    public function setDay(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'count' => ['required', 'integer', 'between:0,10000'],
            'skipped' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ], ['date.before_or_equal' => 'That day hasn’t happened yet.']);
        $skipped = (bool) ($d['skipped'] ?? false);
        HabitController::setDay($habit, $d['date'], $skipped ? 0 : (int) $d['count'], $skipped, array_key_exists('note', $d) ? (trim((string) $d['note']) ?: null) : false);

        return $this->ok($this->row($user, $habit, CarbonImmutable::parse($d['date'], $user->timezone)));
    }

    /** Pause from today, {"until"?: "2026-11-20"}: paused days are neither due nor missed. */
    public function pause(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $until = $request->validate(['until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString()]])['until'] ?? null;
        if (! $habit->pauseOn($today->toDateString())) {
            $habit->pauses()->create(['from' => $today->toDateString(), 'until' => $until]);
        }

        return $this->ok($this->row($user, $habit->fresh(), $today));
    }

    public function resume(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $pause = $habit->pauseOn($today->toDateString());
        if ($pause) {
            $pause->from->toDateString() === $today->toDateString() ? $pause->delete() : $pause->update(['until' => $today->subDay()->toDateString()]);
        }

        return $this->ok($this->row($user, $habit->fresh(), $today));
    }

    public function archive(Request $request, Habit $habit): JsonResponse
    {
        $this->own($habit, $request->user());
        $habit->update(['archived_at' => $request->boolean('undo') ? null : now()]);

        return $this->ok(['id' => $habit->id, 'archived' => $habit->archived_at !== null]);
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
