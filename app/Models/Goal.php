<?php

namespace App\Models;

use App\Support\Clock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use SoftDeletes;

    public const STATUSES = ['active', 'paused', 'achieved', 'dropped'];

    public const PROGRESS = ['tasks', 'milestones', 'manual'];

    protected $fillable = ['title', 'why', 'status', 'target', 'target_date', 'progress_mode', 'progress', 'achieved_at', 'color', 'icon', 'position', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'achieved_at' => 'datetime', 'color' => 'integer', 'target_date' => 'date', 'progress' => 'integer'];
    }

    protected static function booted(): void
    {
        // When it was achieved (for Review): set as it becomes achieved, gone if it stops being so.
        static::saving(function (Goal $goal) {
            if ($goal->isDirty('status')) {
                $goal->achieved_at = $goal->status === 'achieved' ? app(Clock::class)->now() : null;
            }
        });
    }

    /** Progress in % when it isn't counted from tasks (the Goals map counts those itself): milestones reached, or set by hand. */
    public function progressPct(): ?int
    {
        if ($this->progress_mode === 'manual') {
            return (int) ($this->progress ?? 0);
        }
        if ($this->progress_mode === 'milestones') {
            $all = Milestone::whereIn('project_id', $this->projects()->select('id'))->get(['done_at']);

            return $all->isEmpty() ? 0 : (int) round($all->whereNotNull('done_at')->count() / $all->count() * 100);
        }

        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->orderBy('position');
    }
}
