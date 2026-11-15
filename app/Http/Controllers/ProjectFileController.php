<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files on a project itself: attach, open, remove (with undo). Only the owner can reach them. */
class ProjectFileController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:'.TaskFileController::MAX_KB],
        ], ['files.*.max' => 'Each file can be up to 20 MB.'], ['files.*' => 'file']);

        $added = [];
        foreach ($request->file('files') as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            $path = $upload->storeAs("project-files/{$user->id}", Str::uuid().($ext !== '' ? ".{$ext}" : ''), 'local');
            $added[] = $project->files()->create([
                'user_id' => $user->id,
                'name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'path' => $path,
                'mime' => $upload->getMimeType() ?: 'application/octet-stream',
                'size' => $upload->getSize(),
            ]);
        }
        $n = count($added);

        return back()->with('toast', $n === 1 ? "Attached “{$added[0]->name}”" : "Attached {$n} files")
            ->with('undo', $this->undo('remove-project-files', $project->id, ['ids' => array_map(fn ($f) => $f->id, $added)]));
    }

    /** Opens the file in the browser if that is safe (pictures, PDF, plain text), otherwise downloads it. */
    public function show(Request $request, ProjectFile $file): StreamedResponse
    {
        $this->own($file->project, $request->user());
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->path), 404);
        $inline = $file->opensInBrowser() && ! $request->boolean('download');

        return $disk->response($file->path, $file->name, [
            'Content-Type' => $inline ? $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, ProjectFile $file): RedirectResponse
    {
        $this->own($file->project, $request->user());
        $file->delete();

        return back()->with('toast', "Removed “{$file->name}”")->with('undo', $this->undo('restore-project-file', $file->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $file = ProjectFile::withTrashed()->findOrFail($id);
        $this->own($file->project, $request->user());
        $file->restore();

        return back()->with('toast', "“{$file->name}” is back");
    }

    /** Undo of attaching: the files just added are removed again (for good: they were never there). */
    public function removeMany(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $ids = $request->validate(['ids' => ['required', 'array', 'max:10'], 'ids.*' => ['integer']])['ids'];
        $project->files()->withTrashed()->whereKey($ids)->get()->each->forceDelete();

        return back()->with('toast', 'Undone');
    }
}
