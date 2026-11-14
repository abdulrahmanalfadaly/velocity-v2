<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use App\Services\RankedList;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ctrl K: find tasks, projects, goals, notes (by the words inside too), habits,
 * people and classes or events. "See all results" shows more of each on a page.
 */
class SearchController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    /** The palette: a few of each kind, and the #1 task for "Start focus on #1". */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $list = $this->ranking->rank($user);
        $first = $list->scored[0]->task ?? null;
        $top = $first ? ['id' => $first->id, 'title' => $user->tasks()->whereKey($first->id)->value('title')] : null;

        return response()->json(['groups' => mb_strlen($q) < 2 ? [] : $this->find($user, $q, $list, 1), 'top' => $top]);
    }

    /** "See all results": the same search, more of each kind, with the matching words of notes. */
    public function all(Request $request): Response
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('search/index', [
            'q' => $q,
            'groups' => mb_strlen($q) < 2 ? [] : $this->find($user, $q, $this->ranking->rank($user), 6),
        ]);
    }

    /** Each kind's matches; $more multiplies how many of each. */
    private function find(User $user, string $q, RankedList $list, int $more): array
    {
        $like = '%'.addcslashes($q, '%_\\').'%';
        $tasks = $user->tasks()->whereIn('status', [...Task::OPEN, Task::DONE])->where('title', 'like', $like)->with('project')
            ->orderByRaw("case when status = 'done' then 1 else 0 end")->latest('updated_at')->limit(6 * $more)->get()
            ->map(fn ($t) => [
                'kind' => 'task', 'id' => $t->id, 'title' => $t->title,
                'sub' => collect([$list->rankOf($t->id) ? '#'.$list->rankOf($t->id) : ucfirst($t->status), $t->project?->name])->filter()->join(' · '),
                'url' => route('tasks.index', ['task' => $t->id]),
            ]);
        $projects = $user->projects()->whereNull('archived_at')->where('name', 'like', $like)->limit(4 * $more)->get()
            ->map(fn ($p) => ['kind' => 'project', 'id' => $p->id, 'title' => $p->name, 'sub' => 'Project', 'url' => route('projects.show', $p)]);
        $goals = $user->goals()->whereNull('archived_at')->where('title', 'like', $like)->limit(3 * $more)->get()
            ->map(fn ($g) => ['kind' => 'goal', 'id' => $g->id, 'title' => $g->title, 'sub' => 'Goal', 'url' => route('goals.index', ['focus' => 'g'.$g->id])]);
        $notes = $user->notes()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body_text', 'like', $like))->with(['project', 'event'])
            ->orderByRaw('case when title like ? then 0 else 1 end', [$like])->latest('updated_at')->limit(5 * $more)->get()
            ->map(fn ($n) => [
                'kind' => 'note', 'id' => $n->id, 'title' => $n->displayTitle(),
                'sub' => $n->journal_date ? 'Journal' : ($n->project?->name ?? ($n->event_date ? 'Class notes' : 'Note')),
                'url' => route('notes.index', ['in' => $n->journal_date ? 'journal' : ($n->project_id ? "p{$n->project_id}" : null), 'note' => $n->id]),
                'snippet' => $more > 1 ? self::snippet((string) $n->body_text, $q) : null,
            ]);
        $habits = $user->habits()->whereNull('archived_at')->where('name', 'like', $like)->with('project')->orderBy('name')->limit(4 * $more)->get()
            ->map(fn ($h) => ['kind' => 'habit', 'id' => $h->id, 'title' => $h->name, 'sub' => $h->project?->name ?? $h->scheduleLabel(), 'url' => route('habits.index', ['habit' => $h->id])]);
        $people = $user->people()->whereNull('archived_at')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('relationship', 'like', $like)->orWhere('organisation', 'like', $like)
                ->orWhereHas('contacts', fn ($c) => $c->where('value', 'like', $like)))
            ->orderBy('name')->limit(4 * $more)->get()
            ->map(fn ($p) => ['kind' => 'person', 'id' => $p->id, 'title' => $p->name, 'sub' => collect([$p->relationship, $p->organisation])->filter()->join(' · ') ?: 'Person', 'url' => route('people.index', ['person' => $p->id])]);
        $events = $this->events($user, $like, 4 * $more);

        return array_values(array_filter([
            ['label' => 'Tasks', 'items' => $tasks->values()],
            ['label' => 'Projects', 'items' => $projects->values()],
            ['label' => 'Goals', 'items' => $goals->values()],
            ['label' => 'Notes', 'items' => $notes->values()],
            ['label' => 'Classes and events', 'items' => $events],
            ['label' => 'Habits', 'items' => $habits->values()],
            ['label' => 'People', 'items' => $people->values()],
        ], fn ($g) => count($g['items']) > 0));
    }

    /** Classes and events by name or place: each opens the Planner on the next time it happens. */
    private function events(User $user, string $like, int $limit): array
    {
        $now = $this->ranking->now();
        $tz = $user->timezone;

        return $user->events()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('location', 'like', $like))->orderBy('title')->limit($limit)->get()
            ->map(function (Event $e) use ($now, $tz) {
                $next = $e->occurrences($now->startOfDay(), $now->addDays(120), $tz)[0] ?? null;
                $start = $next ? $next['start'] : $e->starts_at;
                $local = $start->timezone($tz);

                return [
                    'kind' => 'event', 'id' => $e->id, 'title' => $e->title,
                    'sub' => collect([ucfirst($e->calendar), $next ? 'next '.$local->format('D j M') : $local->format('j M Y'), $e->location])->filter()->join(' · '),
                    'url' => route('planner.index', ['date' => $local->toDateString(), 'view' => 'day']),
                ];
            })->values()->all();
    }

    /** About 140 characters around the first place the words appear. */
    public static function snippet(string $text, string $q): ?string
    {
        $i = mb_stripos($text, $q);
        if ($i === false) {
            return null;
        }
        $from = max(0, $i - 60);
        $part = mb_substr($text, $from, 140 + mb_strlen($q));

        return ($from > 0 ? '…' : '').Str::squish($part).($from + mb_strlen($part) < mb_strlen($text) ? '…' : '');
    }
}
