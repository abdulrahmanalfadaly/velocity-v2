<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stretch of focused work, usually on a task. Still running while ended_at is
 * empty. A Pomodoro is a row per round; the break after a round is kept on it.
 */
class FocusSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'task_id', 'mode', 'round', 'intention', 'note', 'planned_minutes', 'started_at', 'paused_at', 'paused_seconds', 'ended_at', 'seconds',
        'break_minutes', 'break_started_at', 'was_status',
    ];

    protected function casts(): array
    {
        return [
            'planned_minutes' => 'integer',
            'paused_seconds' => 'integer',
            'seconds' => 'integer',
            'round' => 'integer',
            'break_minutes' => 'integer',
            'started_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'break_started_at' => 'immutable_datetime',
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
            'taskOpen' => $this->task ? $this->task->isOpen() : false,
            'mode' => $this->mode ?? 'timer',
            'round' => $this->round,
            'intention' => $this->intention,
            'plannedMinutes' => $this->planned_minutes,
            'startedAt' => $this->started_at->toIso8601ZuluString(),
            'pausedAt' => $this->paused_at?->toIso8601ZuluString(),
            'pausedSeconds' => $this->paused_seconds,
            'breakMinutes' => $this->break_minutes,
            'breakEndsAt' => $this->break_started_at?->addMinutes($this->break_minutes ?? 0)->toIso8601ZuluString(),
        ];
    }
}
