<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Services\RankingService;
use App\Services\Repeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Templates: tasks to copy from. Made from an existing task or by hand; "Use" creates a new task. */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('tasks/templates', [
            'templates' => $user->templates()->with('project:id,name')->get()->map(fn (TaskTemplate $t) => $this->present($t))->values(),
            'projects' => $user->projects()->whereNull('archived_at')->get(['id', 'name']),
        ]);
    }

    /** From a task ("Save as template" in the panel) or from the form on the Templates page. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($request->filled('task_id')) {
            $task = Task::with('steps')->where('user_id', $user->id)->findOrFail($request->integer('task_id'));
            $template = $user->templates()->create(TaskTemplate::fromTask($task));

            return back()->with('toast', "Saved “{$template->name}” as a template")->with('undo', $this->undo('delete-template', $template->id));
        }
        $template = $user->templates()->create($this->fields($request));

        return back()->with('toast', "Created template “{$template->name}”")->with('undo', $this->undo('delete-template', $template->id));
    }

    public function update(Request $request, TaskTemplate $template): RedirectResponse
    {
        $this->own($template, $request->user());
        $data = $this->fields($request, partial: true);
        $before = $template->only(array_keys($data));
        $template->update($data);

        return back()->with('toast', 'Template saved')->with('undo', $this->undo('patch-template', $template->id, $before));
    }

    public function destroy(Request $request, TaskTemplate $template): RedirectResponse
    {
        $this->own($template, $request->user());
        $template->delete();

        return back()->with('toast', "Deleted template “{$template->name}”")->with('undo', $this->undo('restore-template', $template->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $template = TaskTemplate::withTrashed()->findOrFail($id);
        $this->own($template, $request->user());
        $template->restore();

        return back()->with('toast', "Template “{$template->name}” is back");
    }

    /** Make a task from the template. It is an ordinary task from here on, ranked by the formula. */
    public function use(Request $request, TaskTemplate $template, RankingService $ranking): RedirectResponse
    {
        $user = $request->user();
        $this->own($template, $user);
        $data = $request->validate([
            'due_at' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ]);

        $task = $user->tasks()->create([
            'title' => $template->title,
            'notes' => $template->notes,
            'importance' => $template->importance,
            'urgency' => $template->urgency,
            'est_hours' => $template->est_hours,
            'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $template->project_id,
            'due_at' => $this->fromLocal($data['due_at'] ?? null, $user),
            'repeat_unit' => $template->repeat_unit,
            'repeat_every' => $template->repeat_every,
        ]);
        foreach ($template->steps as $i => $title) {
            $task->steps()->create(['title' => $title, 'position' => $i]);
        }
        $task->events()->create(['user_id' => $user->id, 'type' => 'created', 'snapshot' => ['template' => $template->id]]);
        $template->increment('used');
        $rank = $ranking->rank($user)->rankOf($task->id);

        return to_route('tasks.index', ['task' => $task->id])->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    private function fields(Request $request, bool $partial = false): array
    {
        $user = $request->user();
        $s = $partial ? ['sometimes'] : [];
        $data = $request->validate([
            'name' => [...$s, 'required', 'string', 'max:120'],
            'title' => [...$s, 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'importance' => [...$s, 'required', 'integer', 'between:1,5'],
            'urgency' => [...$s, 'required', 'integer', 'between:1,5'],
            'est_hours' => [...$s, 'required', 'numeric', 'min:0.05', 'max:500'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'repeat_unit' => ['sometimes', 'nullable', Rule::in(Repeat::UNITS)],
            'repeat_every' => ['sometimes', 'integer', 'between:1,99'],
            'steps' => ['sometimes', 'array', 'max:50'],
            'steps.*' => ['nullable', 'string', 'max:255'], // blank lines are dropped below
        ], [], ['est_hours' => 'hours']);
        if (isset($data['steps'])) {
            $data['steps'] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $data['steps']), fn ($x) => $x !== ''));
        } elseif (! $partial) {
            $data['steps'] = []; // a template made without steps
        }

        return $data;
    }

    private function present(TaskTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'title' => $t->title,
            'notes' => $t->notes,
            'importance' => $t->importance,
            'urgency' => $t->urgency,
            'estHours' => $t->est_hours,
            'project' => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name] : null,
            'repeat' => $t->repeat_unit ? ['unit' => $t->repeat_unit, 'every' => $t->repeat_every] : null,
            'steps' => $t->steps,
            'used' => $t->used,
        ];
    }
}
