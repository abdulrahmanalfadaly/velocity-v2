<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files on tasks (the task's own, or on one of its log entries): attach, open, remove (with undo). Only the owner can reach them. */
class TaskFileController extends Controller
{
    public const MAX_KB = 20480; // 20 MB a file

    public function store(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:'.self::MAX_KB],
            'log_id' => ['nullable', 'integer'],
        ], ['files.*.max' => 'Each file can be up to 20 MB.'], ['files.*' => 'file']);
        // On one of the task's log entries, or the task's own.
        $logId = $request->integer('log_id') ?: null;
        abort_if($logId && ! $task->logs()->whereKey($logId)->exists(), 404);

        $added = [];
        foreach ($request->file('files') as $upload) {
            $added[] = self::keep($task, $user, $upload, $logId);
        }
        $n = count($added);
        $toast = $n === 1 ? "Attached “{$added[0]->name}”" : "Attached {$n} files";

        return back()->with('toast', $toast)->with('undo', $this->undo('remove-files', $task->id, ['ids' => array_map(fn ($f) => $f->id, $added)]));
    }

    /** Stores an uploaded file privately and records it on the task (and the log entry, if given). */
    public static function keep(Task $task, User $user, UploadedFile $upload, ?int $logId = null): TaskFile
    {
        $ext = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs("task-files/{$user->id}", Str::uuid().($ext !== '' ? ".{$ext}" : ''), 'local');

        return $task->files()->create([
            'user_id' => $user->id,
            'log_id' => $logId,
            'name' => Str::limit($upload->getClientOriginalName(), 250, ''),
            'path' => $path,
            'mime' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize(),
        ]);
    }

    /** Opens the file in the browser if that is safe (pictures, PDF, plain text), otherwise downloads it. */
    public function show(Request $request, TaskFile $file): StreamedResponse
    {
        $this->own($file->task()->withTrashed()->firstOrFail(), $request->user());
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->path), 404);
        $inline = $file->opensInBrowser() && ! $request->boolean('download');

        return $disk->response($file->path, $file->name, [
            'Content-Type' => $inline ? $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, TaskFile $file): RedirectResponse
    {
        $this->own($file->task, $request->user());
        $file->delete();

        return back()->with('toast', "Removed “{$file->name}”")->with('undo', $this->undo('restore-file', $file->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $file = TaskFile::withTrashed()->findOrFail($id);
        $this->own($file->task()->withTrashed()->firstOrFail(), $request->user());
        $file->restore();

        return back()->with('toast', "“{$file->name}” is back");
    }

    /** Undo of attaching: the files just added are removed again (for good: they were never there). */
    public function removeMany(Request $request, Task $task): RedirectResponse
    {
        $this->own($task, $request->user());
        $ids = $request->validate(['ids' => ['required', 'array', 'max:10'], 'ids.*' => ['integer']])['ids'];
        $task->files()->withTrashed()->whereKey($ids)->get()->each->forceDelete();

        return back()->with('toast', 'Undone');
    }
}
