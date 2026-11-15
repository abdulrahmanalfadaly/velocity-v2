<?php

namespace App\Models;

use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A task to copy from, with its steps. "Use" makes a fresh task that is ranked like any other. */
class TaskTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'name', 'title', 'notes', 'importance', 'urgency', 'est_hours', 'due_days', 'due_time', 'start_days', 'repeat_unit', 'repeat_every', 'steps', 'used'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'importance' => 'integer', 'urgency' => 'integer', 'est_hours' => 'float', 'repeat_every' => 'integer', 'used' => 'integer',
            'due_days' => 'integer', 'start_days' => 'integer'];
    }

    /**
     * Its dates for a task made today (in the user's time zone): due N days after, at its time
     * (or the end of that day); can start N days after (0 or none: now).
     *
     * @return array{due: ?CarbonImmutable, start: ?CarbonImmutable}
     */
    public function datesFrom(CarbonImmutable $todayLocal): array
    {
        $day = $todayLocal->startOfDay();

        return [
            'due' => $this->due_days !== null ? $day->addDays($this->due_days)->setTimeFromTimeString($this->due_time ?: '23:59') : null,
            'start' => $this->start_days ? $day->addDays($this->start_days) : null,
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

    /** What a task made from this template starts with. Its dates become "N days after use", counted from today. */
    public static function fromTask(Task $task, ?CarbonImmutable $nowLocal = null): array
    {
        $nowLocal ??= app(Clock::class)->now()->timezone($task->user?->timezone ?? config('velocity.timezone'));
        $today = $nowLocal->startOfDay();
        $tz = $nowLocal->getTimezone();
        $due = $task->due_at ? CarbonImmutable::instance($task->due_at)->setTimezone($tz) : null;
        $start = $task->available_from ? CarbonImmutable::instance($task->available_from)->setTimezone($tz) : null;
        $after = fn (?CarbonImmutable $at) => $at && $at->startOfDay()->gte($today) ? (int) $today->diffInDays($at->startOfDay()) : null;

        return [
            'due_days' => $after($due),
            'due_time' => $after($due) !== null && $due->format('H:i') !== '23:59' ? $due->format('H:i') : null,
            'start_days' => $after($start) ?: null,
            'project_id' => $task->project_id,
            'name' => $task->title,
            'title' => $task->title,
            'notes' => $task->notes,
            'importance' => $task->importance,
            'urgency' => $task->urgency,
            'est_hours' => $task->est_hours,
            'repeat_unit' => $task->repeat_unit,
            'repeat_every' => $task->repeat_every ?? 1,
            'steps' => $task->steps->pluck('title')->values()->all(),
        ];
    }
}
