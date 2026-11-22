<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\PlannerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Planner: the gap around blocks and how short or long "Plan" makes a block. */
class PlannerSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/planner', [
            'settings' => PlannerSettings::of($user),
            // "Hours a day" from Settings → Tasks, for the "Up to Hours a day" choice.
            'hoursPerDay' => (float) $user->weights()->hours_per_day,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(PlannerSettings::rules());
        $user = $request->user();
        $user->forceFill(['planner_settings' => array_map('intval', array_merge(PlannerSettings::of($user), $data))])->save();

        return back()->with('toast', 'Saved');
    }
}
