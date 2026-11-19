<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One entry in a task's log, written by the user, with any files attached to it. */
class TaskLog extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'task_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class, 'log_id')->oldest('id');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'at' => $this->created_at?->toIso8601ZuluString(),
            // Changed after it was written (more than a minute later).
            'edited' => $this->created_at && $this->updated_at && $this->updated_at->diffInSeconds($this->created_at, true) > 60,
            'files' => $this->files->map(fn (TaskFile $f) => $f->present())->values()->all(),
        ];
    }
}
