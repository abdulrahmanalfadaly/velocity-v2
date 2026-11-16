<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Time booked on the Planner: for a task, for a whole project, or reserved with a
 * label ("Gym", "Keep free"). Planning never books over any of them.
 */
class PlannerBlock extends Model
{
    use SoftDeletes;

    public const KINDS = ['task', 'project', 'reserved'];

    protected $fillable = ['task_id', 'kind', 'project_id', 'label', 'starts_at', 'ends_at', 'pinned', 'locked'];

    protected $attributes = ['kind' => 'task'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'pinned' => 'boolean', 'locked' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The one rule for which blocks count: a task's while the task is still open and not
     * deleted, a project's while the project is not archived or deleted, and reserved
     * time always. The grid, the "booked" totals, free time and planning again all use
     * it, so a finished task's blocks never show, never block time and never count.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereHas('task', fn (Builder $t) => $t->whereIn('status', Task::OPEN))
            ->orWhere(fn (Builder $p) => $p->where('kind', 'project')->whereHas('project', fn (Builder $pr) => $pr->whereNull('archived_at')))
            ->orWhere('kind', 'reserved'));
    }

    /** Booked work (a task or a project); reserved time is not work. */
    public function isWork(): bool
    {
        return $this->kind !== 'reserved';
    }

    /** What the block is for, even when its task or project was deleted since (for messages). */
    public function title(): string
    {
        return match ($this->kind) {
            'project' => $this->project()->withTrashed()->value('name') ?? 'Project',
            'reserved' => $this->label ?: 'Reserved',
            default => $this->task()->withTrashed()->value('title') ?? 'Task',
        };
    }

    public function minutes(): int
    {
        return intdiv($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp(), 60);
    }
}
