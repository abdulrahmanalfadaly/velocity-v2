<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stretch of focused work: on a task, a project, a goal, a habit, a note, or
 * nothing in particular. Still running while ended_at is empty. A Pomodoro is a row
 * per round; the break after a round is kept on it.
 */
class FocusSession extends Model
{
    use SoftDeletes;

    /** What focus can be on besides a task: the column for each. */
    public const SUBJECTS = ['project' => 'project_id', 'goal' => 'goal_id', 'habit' => 'habit_id', 'note' => 'note_id'];

    protected $fillable = [
        'task_id', 'project_id', 'goal_id', 'habit_id', 'note_id', 'mode', 'round', 'intention', 'note', 'planned_minutes', 'started_at', 'paused_at', 'paused_seconds', 'ended_at', 'seconds',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /** The subject columns as they are ({project_id: 3, …}), to carry over to the next Pomodoro round. */
    public function subjectIds(): array
    {
        return $this->only(array_values(self::SUBJECTS));
    }

    /** What the session is on, for the timer and the history: kind, id, name and where it lives. */
    public function subject(): ?array
    {
        $trashed = fn (BelongsTo $r, string $column) => $r->withTrashed()->value($column);

        return match (true) {
            $this->task_id !== null => ['kind' => 'task', 'id' => $this->task_id, 'title' => $this->task?->title ?? $trashed($this->task(), 'title') ?? 'Task', 'href' => "/tasks?task={$this->task_id}"],
            $this->project_id !== null => ['kind' => 'project', 'id' => $this->project_id, 'title' => $trashed($this->project(), 'name') ?? 'Project', 'href' => "/projects/{$this->project_id}"],
            $this->goal_id !== null => ['kind' => 'goal', 'id' => $this->goal_id, 'title' => $trashed($this->goal(), 'title') ?? 'Goal', 'href' => "/goals?focus=g{$this->goal_id}"],
            $this->habit_id !== null => ['kind' => 'habit', 'id' => $this->habit_id, 'title' => $trashed($this->habit(), 'name') ?? 'Habit', 'href' => "/habits?habit={$this->habit_id}"],
            $this->note_id !== null => ['kind' => 'note', 'id' => $this->note_id, 'title' => Note::withTrashed()->find($this->note_id)?->displayTitle() ?? 'Note', 'href' => "/notes?note={$this->note_id}"],
            default => null,
        };
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
            'subject' => $this->subject(),
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
