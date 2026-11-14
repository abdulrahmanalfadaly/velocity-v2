<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reminder of a task: at a time ("Tue 17 Nov, 9:00 am"), before the due
 * date ("1 day before, 9:00 am", moving with it), or "Bug me" every so often
 * until the task is done. It shows when next_at comes and it wasn't shown
 * for that time yet (sent_at < next_at).
 */
class TaskReminder extends Model
{
    use SoftDeletes;

    public const KINDS = ['at', 'before', 'bug'];

    /** "Before due" choices: [minutes before the due time, or days before at 9:00 am]. */
    public const BEFORE = ['15m' => [15, null], '1h' => [60, null], '3h' => [180, null], '0d' => [null, 0], '1d' => [null, 1], '2d' => [null, 2], '7d' => [null, 7]];

    /** "Bug me" every … minutes. */
    public const EVERY = [15, 30, 60, 120];

    public const MAX = 5;

    protected $fillable = ['task_id', 'user_id', 'kind', 'at', 'minutes_before', 'days_before', 'at_time', 'every_minutes', 'next_at', 'sent_at'];

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'next_at' => 'datetime',
            'sent_at' => 'datetime',
            'minutes_before' => 'integer',
            'days_before' => 'integer',
            'every_minutes' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** When it should show, from its kind and the task's due date (null: a "before due" one waits for a due date). */
    public function planned(Task $task, string $tz): ?CarbonImmutable
    {
        if ($this->kind === 'at') {
            return $this->at ? CarbonImmutable::instance($this->at) : null;
        }
        if ($this->kind === 'before') {
            if (! $task->due_at) {
                return null;
            }
            $due = CarbonImmutable::instance($task->due_at);

            return $this->minutes_before !== null
                ? $due->subMinutes($this->minutes_before)
                : $due->timezone($tz)->startOfDay()->subDays($this->days_before ?? 0)->setTimeFromTimeString($this->at_time ?? '09:00')->utc();
        }

        return $this->next_at ? CarbonImmutable::instance($this->next_at) : null; // bug: set as it goes
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'at' => $this->at?->toIso8601ZuluString(),
            'minutesBefore' => $this->minutes_before,
            'daysBefore' => $this->days_before,
            'atTime' => $this->at_time,
            'everyMinutes' => $this->every_minutes,
            'nextAt' => $this->next_at?->toIso8601ZuluString(),
        ];
    }
}
