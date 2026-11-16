<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/** The fields every screen needs about a task (ranked or not). Dates are UTC ISO strings. */
class TaskPresenter
{
    public static function base(Task $task): array
    {
        $project = $task->project;
        $goal = $project ? $project->goal : ($task->goal_id ? $task->goal : null);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'status' => $task->status,
            'importance' => $task->importance,
            'urgency' => $task->urgency,
            'dueAt' => $task->due_at?->toIso8601ZuluString(),
            'estHours' => $task->est_hours,
            'energy' => $task->energy,
            'waitingFor' => $task->waiting_for,
            'availableFrom' => $task->available_from?->toIso8601ZuluString(),
            'completedAt' => $task->completed_at?->toIso8601ZuluString(),
            'scoreAtCompletion' => $task->score_at_completion,
            'followUpOn' => $task->follow_up_on?->toDateString(),
            'waitingSince' => $task->waiting_since?->toIso8601ZuluString(),
            'cancelledAt' => $task->cancelled_at?->toIso8601ZuluString(),
            'cancelReason' => $task->cancel_reason,
            'archivedAt' => $task->archived_at?->toIso8601ZuluString(),
            // Days and an end only when the repeat has them.
            'repeat' => $task->repeat_unit ? ['unit' => $task->repeat_unit, 'every' => $task->repeat_every ?? 1] + array_filter([
                'days' => $task->repeat_days ?: null, 'until' => $task->repeat_until?->toDateString(),
            ]) : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'goalColor' => $project->goal?->color,
                'goalTitle' => $project->goal?->title,
                'dueDate' => $project->due_date?->toDateString(),
            ] : null,
            // The project's goal, or the task's own when it has no project.
            'goal' => $goal ? ['id' => $goal->id, 'title' => $goal->title, 'color' => $goal->color] : null,
            'parent' => $task->relationLoaded('parent') && $task->parent ? ['id' => $task->parent->id, 'title' => $task->parent->title] : null,
            // How many files it has (when counted with the list).
            'fileCount' => $task->files_count ?? null,
            'steps' => $task->relationLoaded('steps')
                ? $task->steps->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'done' => $s->done_at !== null])->values()->all()
                : [],
        ];
    }

    /**
     * The task's history in plain words, newest first: "Due moved Wed 18 Nov → Fri 20 Nov",
     * "Moved to “Chapter 3”", "Done", each with when and (for an access key) via whom.
     */
    public static function activity(Task $task, User $user, int $limit = 50): array
    {
        $events = $task->events()->limit($limit)->get();
        $projects = $user->projects()->pluck('name', 'id');
        $goals = $user->goals()->pluck('title', 'id');
        $tz = $user->timezone;
        $day = fn ($v) => $v ? CarbonImmutable::parse($v)->timezone($tz)->format('D j M') : null;
        $level = fn ($v) => ['', 'very low', 'low', 'medium', 'high', 'very high'][(int) $v] ?? (string) $v;

        $change = fn (string $key, $before, $after): ?string => match ($key) {
            'due_at' => $after === null ? 'Due date cleared' : ($before === null ? 'Due '.$day($after) : 'Due moved '.$day($before).' → '.$day($after)),
            'project_id' => $after === null ? 'Moved to the Inbox' : 'Moved to “'.($projects[$after] ?? 'a project').'”',
            'goal_id' => $after === null ? 'Goal taken off' : 'Goal: “'.($goals[$after] ?? 'a goal').'”',
            'status' => 'Status: '.(['todo' => 'To do', 'doing' => 'Doing', 'waiting' => 'Waiting'][$after] ?? $after),
            'importance' => 'Importance '.$level($after),
            'urgency' => 'Urgency '.$level($after),
            'est_hours' => 'Work '.rtrim(rtrim(number_format((float) $after, 2), '0'), '.').' h',
            'energy' => $after === null ? 'Energy taken off' : 'Energy: '.$after,
            'title' => 'Renamed',
            'notes' => 'Notes changed',
            'available_from' => $after === null ? 'Can start now' : 'Can start '.$day($after),
            'follow_up_on' => $after === null ? 'Follow-up cleared' : 'Follow up '.$day($after),
            'waiting_for' => $after ? "Waiting for {$after}" : null,
            'repeat_unit', 'repeat_days', 'repeat_until' => 'Repeat changed',
            default => null,
        };

        return $events->map(function ($e) use ($change) {
            $snap = $e->snapshot ?? [];
            $text = match ($e->type) {
                'created' => isset($snap['repeatOf']) ? 'Added (the next repeat)' : 'Added',
                'promoted' => 'Made from a step',
                'completed' => 'Done',
                'cancelled' => 'Cancelled'.(! empty($snap['reason']) ? ': '.$snap['reason'] : ''),
                'reopened' => 'Opened again',
                'archived' => 'Archived',
                'unarchived' => 'Out of the archive',
                'deleted' => 'Deleted',
                'restored' => 'Restored',
                'reverted' => 'A change was undone',
                'updated' => implode(' · ', array_values(array_unique(array_filter([
                    ...array_map(fn ($k) => $change($k, $snap['before'][$k] ?? null, $snap['after'][$k] ?? null), array_keys($snap['after'] ?? [])),
                    ! empty($snap['depends_on']) ? 'Starts after changed' : null,
                ])))) ?: 'Changed',
                default => ucfirst($e->type),
            };

            return ['text' => $text, 'at' => $e->created_at?->toIso8601ZuluString(), 'via' => $e->via];
        })->values()->all();
    }

    /** A task that is open but not ranked, with the reason in plain words. */
    public static function notReady(array $row): array
    {
        return self::base($row['task']) + ['kind' => $row['kind'], 'why' => $row['why']];
    }
}
