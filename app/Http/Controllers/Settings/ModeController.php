<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → FYP mode: the report's version of Velocity, or your personal one. */
class ModeController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/mode', ['fypMode' => (bool) $request->user()->fyp_mode]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['fyp_mode' => ['required', 'boolean']]);
        $request->user()->update(['fyp_mode' => $data['fyp_mode']]);

        return to_route('mode.edit')->with('toast', $data['fyp_mode'] ? 'FYP mode is on.' : 'FYP mode is off.');
    }
}
