<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Adding, moving and copying a task's reminders. */
class Reminders
{
    /**
     * A new reminder from the panel or the API: ['kind' => 'at', 'at' => UTC time],
     * ['kind' => 'before', 'before' => '1d'] or ['kind' => 'bug', 'every' => 60].
     */
    public static function add(Task $task, array $data, CarbonImmutable $now): TaskReminder
    {
        if ($task->reminders()->count() >= TaskReminder::MAX) {
            throw ValidationException::withMessages(['kind' => 'A task can have up to '.TaskReminder::MAX.' reminders.']);
        }
        $fields = ['task_id' => $task->id, 'user_id' => $task->user_id, 'kind' => $data['kind']];
        if ($data['kind'] === 'at') {
            $fields['at'] = $data['at'];
        } elseif ($data['kind'] === 'before') {
            [$fields['minutes_before'], $fields['days_before']] = TaskReminder::BEFORE[$data['before']];
            $fields['at_time'] = $fields['days_before'] !== null ? '09:00' : null;
            $same = fn (TaskReminder $r) => $r->minutes_before === $fields['minutes_before'] && $r->days_before === $fields['days_before'];
            if ($task->reminders()->where('kind', 'before')->get()->contains($same)) {
                throw ValidationException::withMessages(['before' => 'That reminder is already there.']);
            }
        } else {
            if ($task->reminders()->where('kind', 'bug')->exists()) {
                throw ValidationException::withMessages(['kind' => 'This task already bugs you.']);
            }
            // It comes every so often, the first time after one such stretch (not the moment it is switched on).
            $fields['every_minutes'] = (int) $data['every'];
            $fields['next_at'] = $now->addMinutes((int) $data['every']);
        }
        $reminder = new TaskReminder($fields);
        $reminder->next_at ??= $reminder->planned($task, $task->user->timezone);
        $reminder->save();

        return $reminder;
    }

    /** The fields of a new reminder ("at" is a time in the user's own zone). */
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(TaskReminder::KINDS)],
            'at' => ['required_if:kind,at', 'nullable', 'date'],
            'before' => ['required_if:kind,before', 'nullable', Rule::in(array_keys(TaskReminder::BEFORE))],
            'every' => ['required_if:kind,bug', 'nullable', 'integer', Rule::in(TaskReminder::EVERY)],
        ];
    }

    /** Show it again at a later time; an "at a time" reminder moves there, the others wait until then. */
    public static function snooze(TaskReminder $reminder, CarbonImmutable $at): void
    {
        $reminder->update(['next_at' => $at] + ($reminder->kind === 'at' ? ['at' => $at] : []));
    }

    /** The old single "reminder_at" field (API): one "at a time" reminder at that time, or none. */
    public static function setAt(Task $task, ?CarbonImmutable $at, CarbonImmutable $now): void
    {
        $task->reminders()->where('kind', 'at')->delete();
        if ($at) {
            self::add($task, ['kind' => 'at', 'at' => $at], $now);
        }
    }

    /** The due date moved: "before due" reminders move with it (and show again for their new time). */
    public static function dueMoved(Task $task): void
    {
        $tz = $task->user->timezone;
        foreach ($task->reminders()->where('kind', 'before')->get() as $r) {
            $r->update(['next_at' => $r->planned($task, $tz)]);
        }
    }

    /** A repeat copy gets the same reminders, moved along by as much as its due date moved. */
    public static function copy(Task $from, Task $to, int $shiftSeconds): void
    {
        $tz = $to->user->timezone;
        foreach ($from->reminders as $r) {
            $copy = $r->replicate(['next_at', 'sent_at']);
            $copy->task_id = $to->id;
            if ($r->kind === 'at') {
                $copy->at = CarbonImmutable::instance($r->at)->addSeconds($shiftSeconds);
            }
            // "Bug me" starts again when the copy's time comes round (as long after its start as before).
            $copy->next_at = $r->kind === 'bug' ? CarbonImmutable::instance($r->created_at)->addSeconds($shiftSeconds) : $copy->planned($to, $tz);
            $copy->save();
        }
    }
}
