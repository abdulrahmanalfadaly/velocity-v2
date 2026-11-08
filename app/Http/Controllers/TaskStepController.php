<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Steps inside a task. A step that grows can become a real, ranked task. */
class TaskStepController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->own($task, $request->user());
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $task->steps()->create($data + ['position' => (int) $task->steps()->max('position') + 1]);

        return back();
    }

    public function update(Request $request, TaskStep $step): RedirectResponse
    {
        $this->own($step->task, $request->user());
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'done' => ['sometimes', 'boolean']]);
        if (array_key_exists('done', $data)) {
            $data['done_at'] = $data['done'] ? now() : null;
            unset($data['done']);
        }
        $step->update($data);

        return back();
    }

    public function destroy(Request $request, TaskStep $step): RedirectResponse
    {
        $this->own($step->task, $request->user());
        $step->delete();

        return back();
    }

    /** Turn a step into its own task: same project and due date, its own score and rank. */
    public function promote(Request $request, TaskStep $step): RedirectResponse
    {
        $user = $request->user();
        $parent = $this->own($step->task, $user);
        $task = $user->tasks()->create([
            'title' => $step->title,
            'parent_id' => $parent->id,
            'project_id' => $parent->project_id,
            'importance' => $parent->importance,
            'urgency' => $parent->urgency,
            'due_at' => $parent->due_at,
            'est_hours' => 1,
        ]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'promoted', 'snapshot' => ['from_step' => $step->id, 'parent' => $parent->id]]);
        $step->delete();

        return back()->with('toast', "“{$task->title}” is now its own task. Set its due date and importance to rank it exactly.");
    }
}
