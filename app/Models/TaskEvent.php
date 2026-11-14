<?php

namespace App\Models;

use App\Support\ApiAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id', 'type', 'snapshot', 'created_at', 'via'];

    protected static function booted(): void
    {
        // A change made with an access key remembers the key's name ("via Mark").
        static::creating(function (TaskEvent $event) {
            $event->via ??= ApiAccess::via();
        });
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
