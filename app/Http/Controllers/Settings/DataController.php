<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AccountTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings → Data: download everything as one .zip, and bring an export into an empty account. */
class DataController extends Controller
{
    public function __construct(private readonly AccountTransfer $transfer) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/data', [
            'empty' => $this->transfer->isEmpty($request->user()),
            'maxUpload' => ini_get('upload_max_filesize') ?: null,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $path = $this->transfer->export($request->user());
        $name = 'velocity-'.now()->timezone($request->user()->timezone)->format('Y-m-d').'.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function import(Request $request): RedirectResponse
    {
        $file = $request->validate(
            ['file' => ['required', 'file', 'mimes:zip', 'max:1048576']],
            ['file.uploaded' => 'That file is bigger than this server takes ('.(ini_get('upload_max_filesize') ?: 'its limit').').', 'file.mimes' => 'Choose the .zip you downloaded from Velocity.'],
        )['file'];
        $counts = $this->transfer->import($request->user(), $file->getRealPath());
        $n = fn (string $t, string $one, string $many) => ($c = $counts[$t] ?? 0) ? "{$c} ".($c === 1 ? $one : $many) : null;
        $what = implode(', ', array_filter([$n('tasks', 'task', 'tasks'), $n('projects', 'project', 'projects'), $n('notes', 'note', 'notes'), $n('habits', 'habit', 'habits'), $n('people', 'person', 'people')]));

        return to_route('home')->with('toast', 'Brought in '.($what ?: 'your settings'));
    }
}
