<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\FocusSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Focus: the timer's length, Pomodoro lengths, what starts by itself, sound. */
class FocusSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/focus', ['settings' => FocusSettings::of($request->user())]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(FocusSettings::rules());
        $request->user()->forceFill(['focus_settings' => $data])->save();

        return back()->with('toast', 'Saved');
    }
}
