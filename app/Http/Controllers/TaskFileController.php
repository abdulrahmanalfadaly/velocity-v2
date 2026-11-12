<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files on tasks: attach, open, remove (with undo). Only the owner can reach them. */
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
        ], ['files.*.max' => 'Each file can be up to 20 MB.'], ['files.*' => 'file']);

        $added = [];
        foreach ($request->file('files') as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            $path = $upload->storeAs("task-files/{$user->id}", Str::uuid().($ext !== '' ? ".{$ext}" : ''), 'local');
            $added[] = $task->files()->create([
                'user_id' => $user->id,
                'name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'path' => $path,
                'mime' => $upload->getMimeType() ?: 'application/octet-stream',
                'size' => $upload->getSize(),
            ]);
        }
        $n = count($added);
        $toast = $n === 1 ? "Attached “{$added[0]->name}”" : "Attached {$n} files";

        return back()->with('toast', $toast)->with('undo', $this->undo('remove-files', $task->id, ['ids' => array_map(fn ($f) => $f->id, $added)]));
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
