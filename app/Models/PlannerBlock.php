<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Time booked on the Planner for a task. */
class PlannerBlock extends Model
{
    use SoftDeletes;

    protected $fillable = ['task_id', 'starts_at', 'ends_at', 'pinned'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'pinned' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The one rule for which blocks count: their task is still open and not deleted.
     * The grid, the "booked" totals, free time and planning again all use it, so a
     * finished task's blocks never show, never block time and never count.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereHas('task', fn (Builder $t) => $t->whereIn('status', Task::OPEN));
    }

    /** The task's title, even when the task was deleted since (for messages). */
    public function title(): string
    {
        return $this->task()->withTrashed()->value('title') ?? 'Task';
    }

    public function minutes(): int
    {
        return intdiv($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp(), 60);
    }
}
