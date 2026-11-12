<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A file attached to a task. Removed files are kept (soft-deleted) so the removal can be undone. */
class TaskFile extends Model
{
    use SoftDeletes;

    /** Types a browser can show safely in a tab. Everything else is downloaded instead of opened. */
    public const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    protected $fillable = ['user_id', 'task_id', 'name', 'path', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        // When the row goes for good, the stored file goes too.
        static::forceDeleted(fn (TaskFile $f) => Storage::disk('local')->delete($f->path));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function opensInBrowser(): bool
    {
        return in_array($this->mime, self::INLINE, true);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'image' => str_starts_with($this->mime, 'image/') && $this->opensInBrowser(),
            'url' => route('files.show', $this),
            'addedAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
