<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Task;
use App\Services\AutoArchive;
use App\Services\RankingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Archived and deleted tasks, deleted notes, archived projects and goals, each with a way back. */
class ArchiveController extends Controller
{
    public function __invoke(Request $request, RankingService $ranking): Response
    {
        $user = $request->user();
        AutoArchive::run($user, $ranking->now());

        return Inertia::render('tasks/archive', [
            'archived' => $user->tasks()->whereNotNull('archived_at')->with('project:id,name')->latest('archived_at')->limit(200)->get()
                ->map(fn (Task $t) => [
                    'id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name, 'status' => $t->status,
                    'finishedAt' => ($t->completed_at ?? $t->cancelled_at)?->toIso8601ZuluString(), 'reason' => $t->cancel_reason,
                ])->values(),
            'tasks' => Task::onlyTrashed()->where('user_id', $user->id)->with('project:id,name')->latest('deleted_at')->limit(100)->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name, 'deletedAt' => $t->deleted_at?->toIso8601ZuluString()])->values(),
            // Deleted notes come back from here too (Undo only lasts a few seconds).
            'notes' => Note::onlyTrashed()->where('user_id', $user->id)->with(['project:id,name', 'event'])->latest('deleted_at')->limit(100)->get()
                ->map(fn (Note $n) => [
                    'id' => $n->id,
                    'title' => $n->displayTitle(),
                    'where' => $n->journal_date ? 'Journal' : ($n->project?->name ?? ($n->event_date ? 'Class notes' : 'Not in a project')),
                    'deletedAt' => $n->deleted_at?->toIso8601ZuluString(),
                ])->values(),
            'projects' => $user->projects()->whereNotNull('archived_at')->latest('archived_at')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'archivedAt' => $p->archived_at?->toIso8601ZuluString(), 'open' => $p->tasks()->open()->count()])->values(),
            'goals' => $user->goals()->whereNotNull('archived_at')->latest('archived_at')->get()
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'color' => $g->color, 'archivedAt' => $g->archived_at?->toIso8601ZuluString()])->values(),
        ]);
    }
}
