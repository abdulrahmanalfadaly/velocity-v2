<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\NoteFile;
use App\Models\TaskFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files in notes: pictures pasted or dropped into the text, and attachments under
 * Files. Stored privately; only the owner can open them.
 */
class NoteFileController extends Controller
{
    /** Answers with JSON: the editor needs each picture's address to place it in the text. */
    public function store(Request $request, Note $note): JsonResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:'.TaskFileController::MAX_KB],
            'inline' => ['sometimes', 'boolean'],
        ], ['files.*.max' => 'Each file can be up to 20 MB.'], ['files.*' => 'file']);
        $inline = $request->boolean('inline');

        $added = [];
        foreach ($request->file('files') as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            $mime = $upload->getMimeType() ?: 'application/octet-stream';
            $added[] = $note->files()->create([
                'user_id' => $user->id,
                'name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'path' => $upload->storeAs("note-files/{$user->id}", Str::uuid().($ext !== '' ? ".{$ext}" : ''), 'local'),
                'mime' => $mime,
                'size' => $upload->getSize(),
                // Only real pictures go into the text; anything else is listed under Files.
                'inline' => $inline && str_starts_with($mime, 'image/') && in_array($mime, TaskFile::INLINE, true),
            ]);
        }
        $note->touch();

        return response()->json(['files' => array_map(fn (NoteFile $f) => $f->present(), $added)]);
    }

    /** Pictures, PDFs and plain text open in the browser; everything else downloads. */
    public function show(Request $request, NoteFile $file): StreamedResponse
    {
        $this->own($file, $request->user());
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->path), 404);
        $inline = $file->opensInBrowser() && ! $request->boolean('download');

        return $disk->response($file->path, $file->name, [
            'Content-Type' => $inline ? $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, NoteFile $file): RedirectResponse
    {
        $this->own($file, $request->user());
        $file->delete();

        return back()->with('toast', "Removed “{$file->name}”")->with('undo', $this->undo('restore-note-file', $file->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $file = NoteFile::onlyTrashed()->findOrFail($id);
        $this->own($file, $request->user());
        $file->restore();

        return back()->with('toast', "“{$file->name}” is back");
    }
}
