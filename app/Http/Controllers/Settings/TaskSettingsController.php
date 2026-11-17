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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['auto_archive_days' => ['nullable', 'integer', Rule::in(AutoArchive::DAYS)]]);
        $request->user()->forceFill(['auto_archive_days' => $data['auto_archive_days'] ?? null])->save();

        return back()->with('toast', 'Saved');
    }
}
