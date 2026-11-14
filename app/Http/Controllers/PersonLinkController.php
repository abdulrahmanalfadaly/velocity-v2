<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Person;
use App\Models\PersonGroup;
use App\Models\Project;
use App\Models\Task;
use App\Services\RankingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * People linked to tasks (what you are waiting on them for, or doing with them),
 * notes and projects; and renaming or removing a group.
 */
class PersonLinkController extends Controller
{
    private const KINDS = ['task' => [Task::class, 'tasks', 'person_task'], 'note' => [Note::class, 'notes', 'person_note'], 'project' => [Project::class, 'projects', 'person_project']];

    public function __construct(private readonly RankingService $ranking) {}

    /** POST people/{person}/link {type, id}: link a task, note or project. */
    public function link(Request $request, Person $person): RedirectResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(self::KINDS))], 'id' => ['required', 'integer']]);
        [$model, $table] = self::KINDS[$d['type']];
        $this->own($model::findOrFail($d['id']), $user);
        $person->{$table}()->syncWithoutDetaching([$d['id']]);

        return back();
    }

    /** POST people/{person}/unlink {type, id}, with Undo. */
    public function unlink(Request $request, Person $person): RedirectResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(self::KINDS))], 'id' => ['required', 'integer']]);
        [$model, $table] = self::KINDS[$d['type']];
        $item = $this->own($model::withoutGlobalScopes()->findOrFail($d['id']), $user);
        $person->{$table}()->detach($d['id']);
        $name = $item->getAttribute('title') ?? $item->getAttribute('name') ?? '';

        return back()->with('toast', 'Unlinked'.($name !== '' ? " “{$name}”" : ''))->with('undo', $this->undo('link-person', $person->id, $d));
    }

    /** A new task about this person: in the Inbox, linked, ranked by the formula like any task. */
    public function newTask(Request $request, Person $person): RedirectResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $title = Str::limit(trim($request->validate(['title' => ['required', 'string', 'max:255']])['title']), 255, '');
        $task = $user->tasks()->create(['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created']);
        $person->tasks()->attach($task->id);
        $rank = $this->ranking->rank($user)->rankOf($task->id);

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    public function renameGroup(Request $request, PersonGroup $group): RedirectResponse
    {
        $this->own($group, $request->user());
        $old = $group->name;
        $group->update(['name' => $request->validate(['name' => ['required', 'string', 'max:60']])['name']]);

        return back()->with('toast', "Renamed to “{$group->name}”")->with('undo', $this->undo('rename-group', $group->id, ['name' => $old]));
    }

    /** The group goes; its people stay. */
    public function destroyGroup(Request $request, PersonGroup $group): RedirectResponse
    {
        $this->own($group, $request->user());
        $group->delete();

        return redirect()->route('people.index')->with('toast', "Deleted the group “{$group->name}”")->with('undo', $this->undo('restore-group', $group->id));
    }

    public function restoreGroup(Request $request, int $id): RedirectResponse
    {
        $group = PersonGroup::onlyTrashed()->findOrFail($id);
        $this->own($group, $request->user());
        $group->restore();

        return redirect()->route('people.index', ['view' => "g{$group->id}"])->with('toast', "“{$group->name}” is back");
    }
}
