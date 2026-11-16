<?php

namespace App\Actions;

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/** A project's status, completing it, and deleting a project or a goal (with Undo), shared by the screens and the API. */
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

    /**
     * Deletes a project (only marked deleted). Its tasks go to the Inbox ("inbox") or are deleted
     * with it ("delete"); its notes and habits stay, out of the project. Returns what Undo needs.
     *
     * @return array{mode: string, tasks: list<int>, notes: list<int>, habits: list<int>}
     */
    public function delete(Project $project, User $user, string $mode = 'inbox'): array
    {
        $tasks = $project->tasks()->get();
        $was = [
            'mode' => $mode, 'tasks' => $tasks->pluck('id')->all(), 'notes' => $project->notes()->pluck('id')->all(), 'habits' => $project->habits()->pluck('id')->all(),
            'shared' => $project->sharedNotes()->pluck('notes.id')->all(),
        ];
        DB::transaction(function () use ($project, $tasks, $mode, $user) {
            if ($mode === 'delete') {
                $tasks->each(fn (Task $t) => $this->tasks->delete($t, $user));
            } else {
                $user->tasks()->whereIn('id', $tasks->pluck('id'))->update(['project_id' => null]);
            }
            // Its notes stay. One that is in other projects too: the next of those becomes its first.
            foreach ($project->notes()->with('extraProjects')->get() as $note) {
                $next = $note->extraProjects->first();
                $note->timestamps = false;
                $note->update(['project_id' => $next?->id]);
                if ($next) {
                    $note->extraProjects()->detach($next->id);
                }
            }
            $project->sharedNotes()->detach();
            $project->habits()->update(['project_id' => null]);
            $project->delete();
        });

        return $was;
    }

    /** Undo of deleting a project: it comes back with its tasks, notes and habits in it again (those still without a project). */
    public function restore(Project $project, User $user, array $was): void
    {
        DB::transaction(function () use ($project, $was, $user) {
            $project->restore();
            if (($was['mode'] ?? 'inbox') === 'delete') {
                Task::onlyTrashed()->where('user_id', $user->id)->whereIn('id', $was['tasks'] ?? [])->get()->each(fn (Task $t) => $this->tasks->restore($t, $user));
            } else {
                $user->tasks()->whereIn('id', $was['tasks'] ?? [])->whereNull('project_id')->update(['project_id' => $project->id]);
            }
            foreach ($user->notes()->whereIn('id', $was['notes'] ?? [])->get() as $note) {
                if ($note->project_id === null) {
                    $note->timestamps = false;
                    $note->update(['project_id' => $project->id]);
                } elseif ((int) $note->project_id !== $project->id) {
                    $note->extraProjects()->syncWithoutDetaching([$project->id]);
                }
            }
            $project->sharedNotes()->syncWithoutDetaching($user->notes()->whereIn('id', $was['shared'] ?? [])->where('project_id', '!=', $project->id)->pluck('id')->all());
            $user->habits()->whereIn('id', $was['habits'] ?? [])->whereNull('project_id')->update(['project_id' => $project->id]);
        });
    }

    /**
     * Deletes a goal (only marked deleted): its projects and tasks stay, without it. A project that
     * serves other goals too gets the next of those as its main goal. Returns what Undo needs.
     *
     * @return array{projects: list<int>, tasks: list<int>, promoted: list<int>}
     */
    public function deleteGoal(Goal $goal, User $user): array
    {
        $was = ['projects' => $goal->projects()->pluck('id')->all(), 'tasks' => $user->tasks()->where('goal_id', $goal->id)->pluck('id')->all(), 'promoted' => []];
        DB::transaction(function () use ($goal, $user, &$was) {
            foreach ($user->projects()->where('goal_id', $goal->id)->with('extraGoals')->get() as $project) {
                $next = $project->extraGoals->first();
                Project::whereKey($project->id)->update(['goal_id' => $next?->id]);
                if ($next) {
                    $project->extraGoals()->detach($next->id);
                    $was['promoted'][] = $project->id;
                }
            }
            $user->tasks()->where('goal_id', $goal->id)->update(['goal_id' => null]);
            // Where it was one of a project's other goals, the link stays: hidden while the goal is deleted, back with Undo.
            $goal->delete();
        });

        return $was;
    }

    /** Undo of deleting a goal: its projects and tasks are under it again (those still without a goal). */
    public function restoreGoal(Goal $goal, User $user, array $was): void
    {
        $goal->restore();
        $user->projects()->whereIn('id', $was['projects'] ?? [])->whereNull('goal_id')->update(['goal_id' => $goal->id]);
        // Projects whose next goal stood in: it is their main goal again, and that one goes back next to it.
        foreach ($user->projects()->whereIn('id', $was['promoted'] ?? [])->whereNotNull('goal_id')->where('goal_id', '!=', $goal->id)->get() as $project) {
            $project->extraGoals()->syncWithoutDetaching([$project->goal_id]);
            $project->extraGoals()->detach($goal->id);
            Project::whereKey($project->id)->update(['goal_id' => $goal->id]);
        }
        $user->tasks()->whereIn('id', $was['tasks'] ?? [])->whereNull('project_id')->whereNull('goal_id')->update(['goal_id' => $goal->id]);
    }

    /** The checks for what an Undo of a delete sends back. */
    public const UNDO_RULES = [
        'mode' => ['sometimes', 'in:inbox,delete'],
        'tasks' => ['nullable', 'array'], 'tasks.*' => ['integer'],
        'notes' => ['nullable', 'array'], 'notes.*' => ['integer'],
        'shared' => ['nullable', 'array'], 'shared.*' => ['integer'],
        'habits' => ['nullable', 'array'], 'habits.*' => ['integer'],
        'projects' => ['nullable', 'array'], 'projects.*' => ['integer'],
        'promoted' => ['nullable', 'array'], 'promoted.*' => ['integer'],
    ];
}
