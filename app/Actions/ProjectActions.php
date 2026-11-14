<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Clock;

/** A project's status and completing it, shared by the screens and the API. */
class ProjectActions
{
    public function __construct(private readonly TaskActions $tasks) {}

    /** Planned, active or on hold. Returns the status it had (for Undo). */
    public function setStatus(Project $project, string $status): string
    {
        $before = $project->status;
        $project->update(['status' => $status, 'completed_at' => null]);

        return $before;
    }

    /**
     * Completes a project: its open tasks are cancelled ($cancelOpen) or stay
     * open. Returns the ids of the tasks it cancelled (for Undo).
     *
     * @return list<int>
     */
    public function complete(Project $project, User $user, bool $cancelOpen): array
    {
        $cancelled = [];
        if ($cancelOpen) {
            foreach ($project->tasks()->whereIn('status', Task::OPEN)->get() as $task) {
                $this->tasks->cancel($task, $user, 'Project completed');
                $cancelled[] = $task->id;
            }
        }
        $project->update(['status' => 'completed', 'completed_at' => app(Clock::class)->now()]);

        return $cancelled;
    }

    /** Open again (a status, active by default); tasks it cancelled when completing open again too. */
    public function reopen(Project $project, User $user, ?string $status = null, array $taskIds = []): void
    {
        $project->update(['status' => $status ?? 'active', 'completed_at' => null]);
        foreach ($project->tasks()->whereIn('id', $taskIds)->where('status', Task::CANCELLED)->get() as $task) {
            $this->tasks->reopen($task, $user);
        }
    }
}
