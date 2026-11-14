<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Note;
use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notes: the list and the open note, saving as you type (JSON, no page reload),
 * the journal (one entry a day), notes for a class on a date, links to tasks.
 */
class NoteController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $in = (string) $request->query('in', 'all');
        $q = trim((string) $request->query('q', ''));
        $open = $request->integer('note') ? $user->notes()->with(['tasks', 'people', 'files' => fn ($f) => $f->where('inline', false), 'event'])->find($request->integer('note')) : null;

        // A safety net for notes left empty when the window was closed: gone after a day.
        $user->notes()->blank()->where('updated_at', '<', $this->ranking->now()->subDay())->when($open, fn ($b) => $b->whereKeyNot($open->id))
            ->get()->each->forceDelete();

        $list = $this->scope($user->notes()->getQuery(), $in);
        if (mb_strlen($q) >= 2) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $list->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body_text', 'like', $like));
        }
        $notes = $list->with(['project.goal:id,color', 'event:id,title,calendar'])->limit(300)->get();

        return Inertia::render('notes/index', [
            'in' => $in,
            'q' => $q,
            'today' => $this->today($user),
            'notes' => $notes->map(fn (Note $n) => $this->item($n))->values(),
            'note' => $open ? $this->detail($open, $user) : null,
            'menu' => $this->menu($user),
            'projects' => $user->projects()->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->when($open?->project && $open->project->archived_at, fn ($c) => $c->push($open->project->only(['id', 'name'])))->values(),
            'taskOptions' => $open ? $user->tasks()->open()->orderBy('title')->limit(400)->get(['id', 'title']) : [],
            'personOptions' => $open ? $user->people()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : [],
            'journalDays' => $in === 'journal' ? $user->notes()->whereNotNull('journal_date')->orderBy('journal_date')->pluck('journal_date')->map(fn ($d) => $d->toDateString())->values() : [],
        ]);
    }

    /** New note: blank, or for a task (titled after it, linked, in its project), or with a title from quick add. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)],
            'person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->where('user_id', $user->id)],
            'title' => ['nullable', 'string', 'max:200'],
            'in' => ['nullable', 'string', 'max:20'],
        ]);
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $note = $user->notes()->create([
            'project_id' => $data['project_id'] ?? $task?->project_id,
            'title' => $data['title'] ?? Str::limit($task->title ?? $person->name ?? '', 200, ''),
            'body_text' => '',
        ]);
        if ($task) {
            $note->tasks()->attach($task->id, ['created_at' => now()]);
        }
        if ($person) {
            $note->people()->attach($person->id);
        }
        $in = $note->project_id ? "p{$note->project_id}" : (($data['in'] ?? null) === 'loose' ? 'loose' : 'all');

        return redirect()->route('notes.index', ['in' => $in, 'note' => $note->id]);
    }

    /** Autosave: the title and the editor's document. Answers with JSON so typing never reloads the page. */
    public function save(Request $request, Note $note): JsonResponse
    {
        $this->own($note, $request->user());
        // The document comes as a JSON string, so the trimming of form input can't touch the spaces inside it.
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:4000000']]);
        if ($request->has('title')) {
            $note->title = (string) ($data['title'] ?? '');
        }
        if ($request->has('body')) {
            $doc = json_decode((string) ($data['body'] ?? ''), true);
            if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
                throw ValidationException::withMessages(['body' => 'The note could not be read.']);
            }
            $note->body = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $note->body_text = Note::textOf($doc);
        }
        $note->save();

        return response()->json(['updatedAt' => $note->updated_at?->toIso8601ZuluString(), 'title' => $note->displayTitle(), 'preview' => $note->preview()]);
    }

    /** Leaving a note with nothing in it: it goes (never shown in the list, never needs deleting). */
    public function discard(Request $request, Note $note): HttpResponse
    {
        $this->own($note, $request->user());
        Note::whereKey($note->id)->blank()->first()?->forceDelete();

        return response()->noContent();
    }

    /** Pin, or move to another project (or out of one). */
    public function update(Request $request, Note $note): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $data = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ]);
        $old = array_intersect_key(['pinned' => $note->pinned, 'project_id' => $note->project_id], $data);
        $note->timestamps = false; // filing a note isn't editing it: it keeps its place in the list
        $note->fill($data)->save();
        if (! $note->wasChanged('project_id')) {
            return back();
        }
        $toast = $note->project_id ? "Moved to {$note->project->name}" : 'Taken out of the project';

        return back()->with('toast', $toast)->with('undo', $this->undo('patch-note', $note->id, $old));
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        $this->own($note, $request->user());
        $note->delete();

        return back()->with('toast', "Deleted “{$note->displayTitle()}”")->with('undo', $this->undo('restore-note', $note->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $note = Note::onlyTrashed()->findOrFail($id);
        $this->own($note, $request->user());
        $note->restore();

        return back()->with('toast', "“{$note->displayTitle()}” is back");
    }

    /** The journal entry for a day (today if none is given): opened if it exists, otherwise started. */
    public function journal(Request $request): RedirectResponse
    {
        $user = $request->user();
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->today($user);
        $note = $this->openOrStart($user, fn (Builder $b) => $b->whereDate('journal_date', $date), ['journal_date' => $date]);

        return redirect()->route('notes.index', ['in' => 'journal', 'note' => $note->id]);
    }

    /** The note for one class (or other event) on one day, from the Planner. */
    public function forEvent(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['event_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $event = $this->own(Event::findOrFail($data['event_id']), $user);
        $note = $this->openOrStart(
            $user,
            fn (Builder $b) => $b->where('event_id', $event->id)->whereDate('event_date', $data['date']),
            ['event_id' => $event->id, 'event_date' => $data['date']],
        );

        return redirect()->route('notes.index', ['in' => "e{$event->id}", 'note' => $note->id]);
    }

    public function link(Request $request, Note $note): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $id = $request->validate(['task_id' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)]])['task_id'];
        $note->tasks()->syncWithoutDetaching([$id => ['created_at' => now()]]);

        return back();
    }

    public function unlink(Request $request, Note $note, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $this->own($task, $user);
        $note->tasks()->detach($task->id);

        return back()->with('toast', "Unlinked “{$task->title}”")->with('undo', $this->undo('link-note-task', $note->id, ['task_id' => $task->id]));
    }

    /** Selected words become a task: in the note's project (or the Inbox), linked to the note. */
    public function makeTask(Request $request, Note $note): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $title = Str::limit(trim(preg_replace('/\s+/u', ' ', $request->validate(['title' => ['required', 'string', 'max:2000']])['title'])), 255, '');
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Select some words first.']);
        }
        $task = $user->tasks()->create(['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'project_id' => $note->project_id]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created']);
        $note->tasks()->attach($task->id, ['created_at' => now()]);
        $rank = $this->ranking->rank($user)->rankOf($task->id);
        $where = $note->project ? $note->project->name : 'Inbox';

        return back()->with('toast', "Added “{$task->title}” to {$where}".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** Which notes a place in the side menu shows, in its order. */
    private function scope(Builder $b, string $in): Builder
    {
        return match (true) {
            $in === 'journal' => $b->whereNotNull('journal_date')->orderByDesc('journal_date'),
            $in === 'pinned' => $b->where('pinned', true)->latest('updated_at'),
            $in === 'loose' => $b->whereNull('project_id')->whereNull('journal_date')->whereNull('event_date')->orderByDesc('pinned')->latest('updated_at'),
            (bool) preg_match('/^p(\d+)$/', $in, $m) => $b->where('project_id', (int) $m[1])->orderByDesc('pinned')->latest('updated_at'),
            (bool) preg_match('/^e(\d+)$/', $in, $m) => $b->where('event_id', (int) $m[1])->orderByDesc('event_date'),
            // All notes: everything but the journal, which has its own place.
            default => $b->whereNull('journal_date')->orderByDesc('pinned')->latest('updated_at'),
        };
    }

    private function menu(User $user): array
    {
        $count = fn (string $in) => $this->scope($user->notes()->getQuery(), $in)->reorder()->count();

        return [
            'all' => $count('all'),
            'pinned' => $count('pinned'),
            'journal' => $count('journal'),
            'loose' => $count('loose'),
            'projects' => $user->projects()->whereHas('notes')->withCount('notes')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'count' => $p->notes_count])->values(),
            'events' => Event::withTrashed()->where('user_id', $user->id)->whereHas('notes')->withCount('notes')->orderBy('title')->get()
                ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'calendar' => $e->calendar, 'count' => $e->notes_count])->values(),
        ];
    }

    /** A note in the list. */
    private function item(Note $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->displayTitle(),
            'untitled' => trim($n->title) === '' && ! $n->journal_date && ! $n->event_date,
            'preview' => $n->preview(),
            'pinned' => $n->pinned,
            'updatedAt' => $n->updated_at?->toIso8601ZuluString(),
            'project' => $n->project ? ['id' => $n->project->id, 'name' => $n->project->name, 'color' => $n->project->goal?->color] : null,
            'journalDate' => $n->journal_date?->toDateString(),
            'eventDate' => $n->event_date?->toDateString(),
            'calendar' => $n->event?->calendar,
        ];
    }

    /** The open note, everything the editor needs. */
    private function detail(Note $n, User $user): array
    {
        $list = $this->ranking->rank($user);

        return [
            'id' => $n->id,
            'title' => $n->title,
            'label' => $n->displayTitle(),
            'doc' => $n->doc(),
            'pinned' => $n->pinned,
            'projectId' => $n->project_id,
            'journalDate' => $n->journal_date?->toDateString(),
            'event' => $n->event_date ? [
                'id' => $n->event_id, 'title' => $n->event?->title ?? 'Class', 'calendar' => $n->event?->calendar ?? 'class',
                'date' => $n->event_date->toDateString(), 'gone' => ! $n->event || $n->event->trashed(),
            ] : null,
            'tasks' => $n->tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'rank' => $list->rankOf($t->id)])->values(),
            'files' => $n->files->map(fn ($f) => $f->present())->values(),
            'people' => $n->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'updatedAt' => $n->updated_at?->toIso8601ZuluString(),
            'createdAt' => $n->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The one note of a kind (a day's journal entry, a class on a date): the live one if
     * there is one, otherwise a new one. A deleted one makes way for the new one.
     */
    public static function openOrStart(User $user, callable $match, array $attrs): Note
    {
        $existing = $match($user->notes()->withTrashed()->getQuery())->first();
        if ($existing && ! $existing->trashed()) {
            return $existing;
        }
        $existing?->forceDelete();
        try {
            return $user->notes()->create($attrs + ['title' => '', 'body_text' => '']);
        } catch (UniqueConstraintViolationException) {
            return $match($user->notes()->getQuery())->firstOrFail(); // a double click got there first
        }
    }

    private function today(User $user): string
    {
        return $this->ranking->now()->timezone($user->timezone)->toDateString();
    }
}
