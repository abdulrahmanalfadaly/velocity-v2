<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A file in a note: a picture placed in the text (inline) or an attachment under Files. */
class NoteFile extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'note_id', 'name', 'path', 'mime', 'size', 'inline'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'inline' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::forceDeleted(fn (NoteFile $f) => Storage::disk('local')->delete($f->path));
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class)->withTrashed();
    }

    public function opensInBrowser(): bool
    {
        return in_array($this->mime, TaskFile::INLINE, true);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'image' => str_starts_with($this->mime, 'image/') && $this->opensInBrowser(),
            // Relative, so a picture kept in the text also works when Velocity is opened from a phone.
            'url' => route('note-files.show', $this, false),
            'inline' => $this->inline,
            'addedAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
