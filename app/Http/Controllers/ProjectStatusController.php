<?php

namespace App\Http\Controllers;

use App\Actions\ProjectActions;
use App\Models\Project;
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
    public function __construct(private readonly ProjectActions $projects) {}

    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $status = $request->validate(['status' => ['required', Rule::in(['planned', 'active', 'on_hold'])]])['status'];
        $before = $this->projects->setStatus($project, $status);
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
        $cancelled = $this->projects->complete($project, $user, $open === 'cancel');
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
        $this->projects->reopen($project, $user, $d['status'] ?? null, $d['tasks'] ?? []);

        return back()->with('toast', "“{$project->name}” is open again");
    }
}
