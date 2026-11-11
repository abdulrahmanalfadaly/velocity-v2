<?php

namespace App\Services;

use App\Models\Task;

/** The fields every screen needs about a task (ranked or not). Dates are UTC ISO strings. */
class TaskPresenter
{
    public static function base(Task $task): array
    {
        $project = $task->project;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'status' => $task->status,
            'importance' => $task->importance,
            'urgency' => $task->urgency,
            'dueAt' => $task->due_at?->toIso8601ZuluString(),
            'estHours' => $task->est_hours,
            'waitingFor' => $task->waiting_for,
            'availableFrom' => $task->available_from?->toIso8601ZuluString(),
            'completedAt' => $task->completed_at?->toIso8601ZuluString(),
            'scoreAtCompletion' => $task->score_at_completion,
            'repeat' => $task->repeat_unit ? ['unit' => $task->repeat_unit, 'every' => $task->repeat_every ?? 1] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'goalColor' => $project->goal?->color,
                'goalTitle' => $project->goal?->title,
            ] : null,
            'parent' => $task->relationLoaded('parent') && $task->parent ? ['id' => $task->parent->id, 'title' => $task->parent->title] : null,
            'steps' => $task->relationLoaded('steps')
                ? $task->steps->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'done' => $s->done_at !== null])->values()->all()
                : [],
        ];
    }

    /** A task that is open but not ranked, with the reason in plain words. */
    public static function notReady(array $row): array
    {
        return self::base($row['task']) + ['kind' => $row['kind'], 'why' => $row['why']];
    }
}
