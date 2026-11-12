<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task to copy from, with its steps. "Use" makes a fresh task that is ranked like any other. */
class TaskTemplate extends Model
{
    protected $fillable = ['project_id', 'name', 'title', 'notes', 'importance', 'urgency', 'est_hours', 'repeat_unit', 'repeat_every', 'steps', 'used'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'importance' => 'integer', 'urgency' => 'integer', 'est_hours' => 'float', 'repeat_every' => 'integer', 'used' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** What a task made from this template starts with. */
    public static function fromTask(Task $task): array
    {
        return [
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
