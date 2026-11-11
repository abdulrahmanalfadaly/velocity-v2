<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskStep;
use App\Support\Clock;
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
        $this->taskOf($step, $request);
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'done' => ['sometimes', 'boolean']]);
        if (array_key_exists('done', $data)) {
            $data['done_at'] = $data['done'] ? app(Clock::class)->now() : null;
            unset($data['done']);
        }
        $step->update($data);

        return back();
    }

    public function destroy(Request $request, TaskStep $step): RedirectResponse
    {
        $this->taskOf($step, $request);
        $step->delete();

        return back();
    }

    /** The step's task, if it is yours (a step of a deleted task is not found). */
    private function taskOf(TaskStep $step, Request $request): Task
    {
        abort_unless($step->task, 404);

        return $this->own($step->task, $request->user());
    }

    /** Turn a step into its own task: same project and due date, its own score and rank. */
    public function promote(Request $request, TaskStep $step): RedirectResponse
    {
        $user = $request->user();
        $parent = $this->taskOf($step, $request);
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

        return back()->with('toast', "“{$task->title}” is now its own task");
    }
}
