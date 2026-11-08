<?php

namespace App\Services;

use App\Models\Habit;
use Carbon\CarbonImmutable;

/** Streaks and today's progress for habits, counted in the user's own days. */
class HabitStats
{
    public static function present(Habit $habit, CarbonImmutable $todayLocal): array
    {
        $entries = $habit->entries->keyBy(fn ($e) => $e->day->toDateString());
        $today = $entries[$todayLocal->toDateString()]->count ?? 0;
        $due = fn (CarbonImmutable $d) => $habit->days === null || in_array($d->dayOfWeekIso, $habit->days, true);

        $streak = 0;
        $day = $today >= $habit->target ? $todayLocal : $todayLocal->subDay();
        for ($guard = 0; $guard < 400; $guard++, $day = $day->subDay()) {
            if (! $due($day)) {
                continue;
            }
            if (($entries[$day->toDateString()]->count ?? 0) < $habit->target) {
                break;
            }
            $streak++;
        }

        return [
            'id' => $habit->id,
            'name' => $habit->name,
            'detail' => $habit->detail,
            'target' => $habit->target,
            'today' => $today,
            'doneToday' => $today >= $habit->target,
            'dueToday' => $due($todayLocal),
            'streak' => $streak,
            'project' => $habit->project ? ['id' => $habit->project->id, 'name' => $habit->project->name] : null,
        ];
    }
}
