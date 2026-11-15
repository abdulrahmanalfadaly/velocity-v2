<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A file on a project itself. Removed files are kept (soft-deleted) so the removal can be undone. */
class ProjectFile extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'project_id', 'name', 'path', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        // When the row goes for good, the stored file goes too.
        static::forceDeleted(fn (ProjectFile $f) => Storage::disk('local')->delete($f->path));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
            'url' => route('project-files.show', $this),
            'addedAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
