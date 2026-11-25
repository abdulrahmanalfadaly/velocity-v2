<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AutoArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Tasks: the weights, horizon and hours a day the ranking uses, and when finished tasks go to the archive by themselves. */
class TaskSettingsController extends Controller
{
    /** "When a task starts waiting, follow up after": off, or this many days. */
    public const FOLLOW_UP_DAYS = [0, 1, 2, 3, 5, 7, 14];

    /** "Remind me on the day at": these times, or off (null). */
    public const FOLLOW_UP_TIMES = ['07:00', '08:00', '09:00', '10:00', '12:00', '14:00', '17:00', '19:00'];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profile = $user->weights();

        return Inertia::render('settings/tasks', [
            'autoArchiveDays' => $user->auto_archive_days,
            'weights' => $profile->weights()->toPercentages(),
            'isDefault' => $profile->weights()->isDefault(),
            'horizonDays' => $profile->horizon_days,
            'hoursPerDay' => $profile->hours_per_day,
            'followUpDays' => (int) $user->follow_up_days,
            'followUpRemindAt' => $user->follow_up_remind_at,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_archive_days' => ['sometimes', 'nullable', 'integer', Rule::in(AutoArchive::DAYS)],
            'follow_up_days' => ['sometimes', 'integer', Rule::in(self::FOLLOW_UP_DAYS)],
            'follow_up_remind_at' => ['sometimes', 'nullable', Rule::in(self::FOLLOW_UP_TIMES)],
        ]);
        $request->user()->forceFill($data)->save();

        return back()->with('toast', 'Saved');
    }
}
