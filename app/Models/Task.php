<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    public const TODO = 'todo';

    public const DOING = 'doing';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::TODO, self::DOING, self::WAITING];

    public const STATUSES = [self::TODO, self::DOING, self::WAITING, self::DONE, self::CANCELLED];

    protected $fillable = [
        'project_id', 'parent_id', 'title', 'notes', 'importance', 'urgency', 'due_at', 'est_hours',
        'status', 'waiting_for', 'available_from', 'completed_at', 'score_at_completion', 'reminder_at',
        'repeat_unit', 'repeat_every', 'repeated_from_id',
    ];

    protected function casts(): array
    {
        return [
            'importance' => 'integer',
            'urgency' => 'integer',
            'est_hours' => 'float',
            'score_at_completion' => 'float',
            'due_at' => 'datetime',
            'available_from' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_at' => 'datetime',
            'repeat_every' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->latest('created_at');
    }

    /** Tasks this one waits for: it cannot start until they are done. */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_id');
    }

    /** For a repeating task: the copy that was created when this one was finished. */
    public function nextRepeat(): HasOne
    {
        return $this->hasOne(Task::class, 'repeated_from_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
