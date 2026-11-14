<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ctrl K: find tasks, projects, goals and notes by name. */
class SearchController extends Controller
{
    public function __invoke(Request $request, RankingService $ranking): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $list = $ranking->rank($user);

        $tasks = $user->tasks()->whereIn('status', [...Task::OPEN, Task::DONE])->where('title', 'like', $like)->with('project')
            ->orderByRaw("case when status = 'done' then 1 else 0 end")->latest('updated_at')->limit(6)->get()
            ->map(fn ($t) => [
                'kind' => 'task', 'id' => $t->id, 'title' => $t->title,
                'sub' => collect([$list->rankOf($t->id) ? '#'.$list->rankOf($t->id) : ucfirst($t->status), $t->project?->name])->filter()->join(' · '),
                'url' => route('tasks.index', ['task' => $t->id]),
            ]);
        $projects = $user->projects()->whereNull('archived_at')->where('name', 'like', $like)->limit(4)->get()
            ->map(fn ($p) => ['kind' => 'project', 'id' => $p->id, 'title' => $p->name, 'sub' => 'Project', 'url' => route('projects.show', $p)]);
        $goals = $user->goals()->whereNull('archived_at')->where('title', 'like', $like)->limit(3)->get()
            ->map(fn ($g) => ['kind' => 'goal', 'id' => $g->id, 'title' => $g->title, 'sub' => 'Goal', 'url' => route('goals.index', ['focus' => 'g'.$g->id])]);
        $notes = $user->notes()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body_text', 'like', $like))->with(['project', 'event'])
            ->orderByRaw('case when title like ? then 0 else 1 end', [$like])->latest('updated_at')->limit(5)->get()
            ->map(fn ($n) => [
                'kind' => 'note', 'id' => $n->id, 'title' => $n->displayTitle(),
                'sub' => $n->journal_date ? 'Journal' : ($n->project?->name ?? ($n->event_date ? 'Class notes' : 'Note')),
                'url' => route('notes.index', ['in' => $n->journal_date ? 'journal' : ($n->project_id ? "p{$n->project_id}" : null), 'note' => $n->id]),
            ]);

        $habits = $user->habits()->whereNull('archived_at')->where('name', 'like', $like)->with('project')->orderBy('name')->limit(4)->get()
            ->map(fn ($h) => ['kind' => 'habit', 'id' => $h->id, 'title' => $h->name, 'sub' => $h->project?->name ?? $h->scheduleLabel(), 'url' => route('habits.index', ['habit' => $h->id])]);

        $people = $user->people()->whereNull('archived_at')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('relationship', 'like', $like)->orWhere('organisation', 'like', $like)
                ->orWhereHas('contacts', fn ($c) => $c->where('value', 'like', $like)))
            ->orderBy('name')->limit(4)->get()
            ->map(fn ($p) => ['kind' => 'person', 'id' => $p->id, 'title' => $p->name, 'sub' => collect([$p->relationship, $p->organisation])->filter()->join(' · ') ?: 'Person', 'url' => route('people.index', ['person' => $p->id])]);

        return response()->json(['groups' => array_values(array_filter([
            ['label' => 'Tasks', 'items' => $tasks->values()],
            ['label' => 'Projects', 'items' => $projects->values()],
            ['label' => 'Goals', 'items' => $goals->values()],
            ['label' => 'Notes', 'items' => $notes->values()],
            ['label' => 'Habits', 'items' => $habits->values()],
            ['label' => 'People', 'items' => $people->values()],
        ], fn ($g) => count($g['items']) > 0))]);
    }
}
