<?php

namespace App\Http\Controllers;

use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A project's milestones: the big points along the way, shown under the project's header. */
class MilestoneController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->own($project, $request->user());
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'due_date' => ['nullable', 'date']]);
        $project->milestones()->create($data + ['position' => (int) $project->milestones()->max('position') + 1]);

        return back();
    }

    public function update(Request $request, Milestone $milestone): RedirectResponse
    {
        $this->own($milestone->project, $request->user());
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'done' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('done', $data)) {
            $data['done_at'] = $data['done'] ? now() : null;
            unset($data['done']);
        }
        $milestone->update($data);

        return back();
    }

    public function destroy(Request $request, Milestone $milestone): RedirectResponse
    {
        $this->own($milestone->project, $request->user());
        $milestone->delete();

        return back()->with('toast', "Removed milestone “{$milestone->title}”");
    }
}
