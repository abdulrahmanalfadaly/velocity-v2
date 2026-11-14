<?php

namespace App\Http\Controllers;

use App\Actions\TaskActions;
use App\Models\Project;
use App\Models\Task;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A project's status: planned, active or on hold (a planned or on-hold
 * project's tasks leave Up next, like waiting ones), and completing it —
 * its open tasks are cancelled or kept open, as chosen. Every change has Undo.
 */
class ProjectStatusController extends Controller
{
    public function __construct(private readonly TaskActions $tasks) {}

    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $status = $request->validate(['status' => ['required', Rule::in(['planned', 'active', 'on_hold'])]])['status'];
        $before = $project->status;
        $project->update(['status' => $status, 'completed_at' => null]);
        $label = ['planned' => 'Planned', 'active' => 'Active', 'on_hold' => 'On hold'][$status];

        return back()->with('toast', "“{$project->name}”: {$label}")->with('undo', $this->undo('project-status', $project->id, ['status' => $before]));
    }

    /** "Complete project": the open tasks are cancelled ("cancel") or stay open ("keep"). */
    public function complete(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $open = $request->validate(['open' => ['required', Rule::in(['cancel', 'keep'])]])['open'];
        if ($project->status === 'completed') {
            return back();
        }
        $before = $project->status;
        $cancelled = [];
        if ($open === 'cancel') {
            foreach ($project->tasks()->whereIn('status', Task::OPEN)->get() as $task) {
                $this->tasks->cancel($task, $user, 'Project completed');
                $cancelled[] = $task->id;
            }
        }
        $project->update(['status' => 'completed', 'completed_at' => app(Clock::class)->now()]);
        $more = $cancelled ? ' · '.count($cancelled).' open '.(count($cancelled) === 1 ? 'task' : 'tasks').' cancelled' : '';

        return back()->with('toast', "Completed “{$project->name}”{$more}")
            ->with('undo', $this->undo('uncomplete-project', $project->id, ['status' => $before, 'tasks' => $cancelled]));
    }

    /** Reopen a completed project, or undo "Complete": back to its status, and the tasks it cancelled open again. */
    public function reopen(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $d = $request->validate(['status' => ['nullable', Rule::in(['planned', 'active', 'on_hold'])], 'tasks' => ['nullable', 'array'], 'tasks.*' => ['integer']]);
        $project->update(['status' => $d['status'] ?? 'active', 'completed_at' => null]);
        foreach ($project->tasks()->whereIn('id', $d['tasks'] ?? [])->where('status', Task::CANCELLED)->get() as $task) {
            $this->tasks->reopen($task, $user);
        }

        return back()->with('toast', "“{$project->name}” is open again");
    }
}
