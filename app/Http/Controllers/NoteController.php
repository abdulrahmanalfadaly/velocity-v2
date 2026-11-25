<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Note;
use App\Models\NoteLink;
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
        $open = $request->integer('note') ? $user->notes()->with(['tasks', 'people', 'links', 'files' => fn ($f) => $f->where('inline', false), 'event'])->find($request->integer('note')) : null;

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
            // What "Link note, goal or habit" can choose from.
            'linkOptions' => $open ? [
                'note' => $user->notes()->whereKeyNot($open->id)->whereNull('archived_at')->with('event')->latest('updated_at')->limit(300)->get()->map(fn (Note $n) => ['id' => $n->id, 'title' => $n->displayTitle()])->values(),
                'goal' => $user->goals()->whereNull('archived_at')->orderBy('title')->get(['id', 'title']),
                'habit' => $user->habits()->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->map(fn ($h) => ['id' => $h->id, 'title' => $h->name])->values(),
            ] : null,
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
            'text' => ['nullable', 'string', 'max:200000'],
            'in' => ['nullable', 'string', 'max:20'],
            // Quick add: save it and stay where you are.
            'stay' => ['sometimes', 'boolean'],
        ]);
        $text = trim($data['text'] ?? '');
        if (($data['stay'] ?? false) && $text === '' && trim($data['title'] ?? '') === '') {
            throw ValidationException::withMessages(['title' => 'A note needs a title or some text.']);
        }
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        // Made while looking at an area's notes: it goes in that area.
        $area = preg_match('/^a(\d+)$/', $data['in'] ?? '', $m) ? $user->areas()->find((int) $m[1]) : null;
        $note = $user->notes()->create([
            'project_id' => $data['project_id'] ?? $task?->project_id,
            'area_id' => $area?->id,
            'title' => $data['title'] ?? Str::limit($task->title ?? $person->name ?? '', 200, ''),
            'body_text' => $text,
        ] + ($text !== '' ? ['body' => json_encode(Note::docFromText($text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] : []));
        if ($task) {
            $note->tasks()->attach($task->id, ['created_at' => now()]);
        }
        if ($person) {
            $note->people()->attach($person->id);
        }
        if ($data['stay'] ?? false) {
            return back()->with('toast', "Added note “{$note->displayTitle()}”")
                ->with('undo', $this->undo('delete-note', $note->id))
                ->with('toastOpen', route('notes.index', ['note' => $note->id], false));
        }
        $in = $note->project_id ? "p{$note->project_id}" : ($area ? "a{$area->id}" : (($data['in'] ?? null) === 'loose' ? 'loose' : 'all'));

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
        if ($note->archived_at) {
            throw ValidationException::withMessages(['body' => 'This note is archived: restore it to change it.']);
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

    /** Archive: kept, read-only, out of the lists (Notes › Archived). Undo or Restore brings it back. */
    public function archive(Request $request, Note $note): RedirectResponse
    {
        $this->own($note, $request->user());
        $note->timestamps = false;
        $note->update(['archived_at' => $this->ranking->now()]);

        return back()->with('toast', "Archived “{$note->displayTitle()}”")->with('undo', $this->undo('unarchive-note', $note->id));
    }

    public function unarchive(Request $request, Note $note): RedirectResponse
    {
        $this->own($note, $request->user());
        $note->timestamps = false;
        $note->update(['archived_at' => null]);

        return back()->with('toast', "“{$note->displayTitle()}” is back");
    }

    /** Leaving a note with nothing in it: it goes (never shown in the list, never needs deleting). */
    public function discard(Request $request, Note $note): HttpResponse
    {
        $this->own($note, $request->user());
        Note::whereKey($note->id)->blank()->first()?->forceDelete();

        return response()->noContent();
    }

    /**
     * Pin, file into projects ("project_ids": the first is its own, where tasks made from it go;
     * "project_id" moves it to one) or into an area.
     */
    public function update(Request $request, Note $note): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $own = fn (string $table) => Rule::exists($table, 'id')->where('user_id', $user->id);
        $data = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'nullable', 'integer', $own('projects')],
            'project_ids' => ['sometimes', 'array', 'max:20'],
            'project_ids.*' => ['integer', 'distinct', $own('projects')],
            'area_id' => ['sometimes', 'nullable', 'integer', $own('areas')],
        ]);
        $old = array_intersect_key(['pinned' => $note->pinned, 'project_id' => $note->project_id, 'project_ids' => $note->projectIds(), 'area_id' => $note->area_id], $data);
        $note->timestamps = false; // filing a note isn't editing it: it keeps its place in the list
        if (array_key_exists('project_ids', $data)) {
            $ids = array_values(array_map('intval', $data['project_ids']));
            $note->project_id = $ids[0] ?? null;
            $note->extraProjects()->sync(array_slice($ids, 1));
            unset($data['project_ids']);
        }
        $note->fill($data)->save();
        if (array_key_exists('project_id', $data)) {
            $note->extraProjects()->detach($note->project_id);
        }
        $toast = match (true) {
            array_key_exists('project_ids', $old) && $old['project_ids'] !== $note->projectIds() => match (count($note->projectIds())) {
                0 => 'Taken out of its projects',
                1 => "In {$note->project->name}",
                default => 'In '.count($note->projectIds()).' projects',
            },
            array_key_exists('project_id', $data) && $note->wasChanged('project_id') => $note->project_id ? "Moved to {$note->project->name}" : 'Taken out of the project',
            array_key_exists('area_id', $data) && $note->wasChanged('area_id') => $note->area_id ? "Moved to {$note->area->name}" : 'Taken out of the area',
            default => null,
        };

        return $toast ? back()->with('toast', $toast)->with('undo', $this->undo('patch-note', $note->id, $old)) : back();
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

    /** Link a note to another note, a goal or a habit (from the note's "Link" picker). */
    public function linkTo(Request $request, Note $note): RedirectResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $d = $request->validate(['type' => ['required', Rule::in(NoteLink::TYPES)], 'id' => ['required', 'integer']]);
        NoteLink::attach($user, $note, $d['type'], (int) $d['id']);

        return back();
    }

    public function unlinkFrom(Request $request, Note $note, NoteLink $link): RedirectResponse
    {
        $this->own($note, $request->user());
        abort_unless($link->note_id === $note->id, 404);
        $user = $request->user();
        // What it pointed at, for the message ("Unlinked “Chapter 4 outline”").
        $name = match ($link->linkable_type) {
            'note' => $user->notes()->find($link->linkable_id)?->displayTitle(),
            'goal' => $user->goals()->find($link->linkable_id)?->title,
            default => $user->habits()->find($link->linkable_id)?->name,
        };
        $link->delete();

        return back()->with('toast', 'Unlinked'.($name ? " “{$name}”" : ''))
            ->with('undo', $this->undo('link-note', $note->id, ['type' => $link->linkable_type, 'id' => $link->linkable_id]));
    }

    /** A note's links with what they point at (gone ones left out). */
    private function links(Note $n, User $user): array
    {
        $titles = [
            'note' => $user->notes()->whereIn('id', $n->links->where('linkable_type', 'note')->pluck('linkable_id'))->with('event')->get()->mapWithKeys(fn (Note $x) => [$x->id => $x->displayTitle()]),
            'goal' => $user->goals()->whereIn('id', $n->links->where('linkable_type', 'goal')->pluck('linkable_id'))->pluck('title', 'id'),
            'habit' => $user->habits()->whereIn('id', $n->links->where('linkable_type', 'habit')->pluck('linkable_id'))->pluck('name', 'id'),
        ];
        $href = fn (string $type, int $id) => match ($type) {
            'note' => route('notes.index', ['note' => $id]),
            'goal' => route('goals.index', ['focus' => "g{$id}"]),
            default => route('habits.index', ['view' => 'all', 'habit' => $id]),
        };

        return $n->links->filter(fn (NoteLink $l) => isset($titles[$l->linkable_type][$l->linkable_id]))
            ->map(fn (NoteLink $l) => [
                'id' => $l->id, 'type' => $l->linkable_type, 'targetId' => $l->linkable_id,
                'title' => $titles[$l->linkable_type][$l->linkable_id], 'href' => $href($l->linkable_type, $l->linkable_id),
            ])->values()->all();
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
        // The words it came from, to jump back to them from the task.
        $note->tasks()->attach($task->id, ['created_at' => now(), 'quote' => Str::limit($title, 300, '')]);
        $rank = $this->ranking->rank($user)->rankOf($task->id);
        $where = $note->project ? $note->project->name : 'Inbox';

        return back()->with('toast', "Added “{$task->title}” to {$where}".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** Which notes a place in the side menu shows, in its order. Archived notes show only under Archived. */
    private function scope(Builder $b, string $in): Builder
    {
        if ($in === 'archived') {
            return $b->whereNotNull('archived_at')->latest('archived_at');
        }
        $b->whereNull('archived_at');

        return match (true) {
            $in === 'journal' => $b->whereNotNull('journal_date')->orderByDesc('journal_date'),
            $in === 'pinned' => $b->where('pinned', true)->latest('updated_at'),
            $in === 'loose' => $b->whereNull('project_id')->whereNull('journal_date')->whereNull('event_date')->orderByDesc('pinned')->latest('updated_at'),
            (bool) preg_match('/^p(\d+)$/', $in, $m) => $b->inProject((int) $m[1])->orderByDesc('pinned')->latest('updated_at'),
            (bool) preg_match('/^a(\d+)$/', $in, $m) => $b->where('area_id', (int) $m[1])->orderByDesc('pinned')->latest('updated_at'),
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
            'archived' => $count('archived'),
            'projects' => $user->projects()->withCount(['notes' => fn ($q) => $q->whereNull('archived_at'), 'sharedNotes' => fn ($q) => $q->whereNull('archived_at')])
                ->orderBy('name')->get()->filter(fn ($p) => $p->notes_count + $p->shared_notes_count > 0)
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'count' => $p->notes_count + $p->shared_notes_count])->values(),
            'areas' => $user->areas()->withCount(['notes' => fn ($q) => $q->whereNull('archived_at')])->orderBy('position')->get()
                ->filter(fn ($a) => $a->notes_count > 0)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'icon' => $a->icon, 'count' => $a->notes_count])->values(),
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
            'archivedAt' => $n->archived_at?->toIso8601ZuluString(),
            'projectId' => $n->project_id,
            'projectIds' => $n->projectIds(),
            'areaId' => $n->area_id,
            'journalDate' => $n->journal_date?->toDateString(),
            'event' => $n->event_date ? [
                'id' => $n->event_id, 'title' => $n->event?->title ?? 'Class', 'calendar' => $n->event?->calendar ?? 'class',
                'date' => $n->event_date->toDateString(), 'gone' => ! $n->event || $n->event->trashed(),
            ] : null,
            'tasks' => $n->tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'rank' => $list->rankOf($t->id)])->values(),
            'files' => $n->files->map(fn ($f) => $f->present())->values(),
            'people' => $n->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'links' => $this->links($n, $user),
            // Other notes that link to this one.
            'linkedFrom' => NoteLink::notesFor($user, 'note', $n->id),
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
