<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stretch of focused work, usually on a task. Still running while ended_at is empty. */
class FocusSession extends Model
{
    protected $fillable = ['task_id', 'planned_minutes', 'started_at', 'paused_at', 'paused_seconds', 'ended_at', 'seconds', 'was_status'];

    protected function casts(): array
    {
        return [
            'planned_minutes' => 'integer',
            'paused_seconds' => 'integer',
            'seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Focused seconds so far: from the start to now (or to the pause, or the end), minus time spent paused. */
    public function elapsed(CarbonImmutable $now): int
    {
        if ($this->seconds !== null) {
            return $this->seconds;
        }
        $until = $this->paused_at ?? $now;

        return max(0, $until->getTimestamp() - $this->started_at->getTimestamp() - $this->paused_seconds);
    }

    /** What the open window needs to draw the timer (it counts on by itself from these). */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'taskId' => $this->task_id,
            'taskTitle' => $this->task?->title,
            'plannedMinutes' => $this->planned_minutes,
            'startedAt' => $this->started_at->toIso8601ZuluString(),
            'pausedAt' => $this->paused_at?->toIso8601ZuluString(),
            'pausedSeconds' => $this->paused_seconds,
        ];
    }
}
