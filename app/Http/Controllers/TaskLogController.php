<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\TaskLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A task's log: entries the user writes (with files if they want), edited or removed (with Undo). */
class TaskLogController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.TaskFileController::MAX_KB],
        ], ['files.*.max' => 'Each file can be up to 20 MB.'], ['files.*' => 'file']);
        $body = trim((string) ($data['body'] ?? ''));
        $uploads = $request->file('files') ?? [];
        if ($body === '' && ! $uploads) {
            throw ValidationException::withMessages(['body' => 'Write something or attach a file.']);
        }
        $log = $task->logs()->create(['user_id' => $user->id, 'body' => $body === '' ? null : $body]);
        foreach ($uploads as $upload) {
            TaskFileController::keep($task, $user, $upload, $log->id);
        }

        return back();
    }

    public function update(Request $request, TaskLog $log): RedirectResponse
    {
        $this->taskOf($log, $request);
        $body = trim((string) ($request->validate(['body' => ['nullable', 'string', 'max:5000']])['body'] ?? ''));
        if ($body === '' && ! $log->files()->exists()) {
            throw ValidationException::withMessages(['body' => 'Write something, or remove the entry.']);
        }
        $log->update(['body' => $body === '' ? null : $body]);

        return back();
    }

    /** Removes the entry and its files; Undo brings both back. */
    public function destroy(Request $request, TaskLog $log): RedirectResponse
    {
        $this->taskOf($log, $request);
        $files = $log->files()->get();
        $files->each->delete();
        $log->delete();

        return back()->with('toast', 'Removed the log entry')
            ->with('undo', $this->undo('restore-log', $log->id, ['files' => $files->pluck('id')->all()]));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $log = TaskLog::withTrashed()->findOrFail($id);
        $this->taskOf($log, $request);
        $ids = $request->validate(['files' => ['nullable', 'array', 'max:100'], 'files.*' => ['integer']])['files'] ?? [];
        $log->restore();
        TaskFile::onlyTrashed()->where('log_id', $log->id)->whereKey($ids)->get()->each->restore();

        return back()->with('toast', 'The log entry is back');
    }

    /** The entry's task, if it is yours (an entry of a deleted task is not found). */
    private function taskOf(TaskLog $log, Request $request): Task
    {
        abort_unless($log->task, 404);

        return $this->own($log->task, $request->user());
    }
}
