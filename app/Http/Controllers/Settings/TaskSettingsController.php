<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AutoArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Tasks: when finished tasks go to the archive by themselves. */
class TaskSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/tasks', ['autoArchiveDays' => $request->user()->auto_archive_days]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['auto_archive_days' => ['nullable', 'integer', Rule::in(AutoArchive::DAYS)]]);
        $request->user()->forceFill(['auto_archive_days' => $data['auto_archive_days'] ?? null])->save();

        return back()->with('toast', 'Saved');
    }
}
