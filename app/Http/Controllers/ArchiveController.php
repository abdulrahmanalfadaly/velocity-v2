<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Deleted tasks and archived projects and goals, each with a way back. */
class ArchiveController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('tasks/archive', [
            'tasks' => Task::onlyTrashed()->where('user_id', $user->id)->with('project:id,name')->latest('deleted_at')->limit(100)->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name, 'deletedAt' => $t->deleted_at?->toIso8601ZuluString()])->values(),
            'projects' => $user->projects()->whereNotNull('archived_at')->latest('archived_at')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'archivedAt' => $p->archived_at?->toIso8601ZuluString(), 'open' => $p->tasks()->open()->count()])->values(),
            'goals' => $user->goals()->whereNotNull('archived_at')->latest('archived_at')->get()
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'color' => $g->color, 'archivedAt' => $g->archived_at?->toIso8601ZuluString()])->values(),
        ]);
    }
}
