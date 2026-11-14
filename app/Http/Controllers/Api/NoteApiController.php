<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\NoteController;
use App\Models\Note;
use App\Support\ApiPresent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Notes and the journal. Text comes and goes as plain text: each line becomes a
 * paragraph (the screens keep their formatting; "doc" has the full document).
 */
class NoteApiController extends ApiController
{
    /** in=all (not the journal, default) | journal | pinned; q searches titles and words; limit. */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'in' => ['nullable', Rule::in(['all', 'journal', 'pinned'])],
            'q' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $in = $d['in'] ?? 'all';
        $like = isset($d['q']) ? '%'.addcslashes($d['q'], '%_\\').'%' : null;
        $notes = $request->user()->notes()->with(['project', 'event'])
            ->when($in === 'journal', fn (Builder $b) => $b->whereNotNull('journal_date')->orderByDesc('journal_date'))
            ->when($in === 'pinned', fn (Builder $b) => $b->where('pinned', true))
            ->when($in === 'all', fn (Builder $b) => $b->whereNull('journal_date'))
            ->when(isset($d['project_id']), fn (Builder $b) => $b->where('project_id', $d['project_id']))
            ->when($like, fn (Builder $b) => $b->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body_text', 'like', $like)))
            // Untouched blank notes (opened and left) are not shown, as on the screens.
            ->where(fn ($w) => $w->where('title', '!=', '')->orWhere('body_text', '!=', '')->orWhereNotNull('journal_date'))
            ->latest('updated_at')->limit($d['limit'] ?? 50)->get();

        return $this->ok($notes->map(fn (Note $n) => ApiPresent::note($n))->values());
    }

    public function show(Request $request, Note $note): JsonResponse
    {
        $this->own($note, $request->user());

        return $this->ok(ApiPresent::note($note->load(['project', 'event', 'tasks', 'people']), true));
    }

    /** {"title": "Meeting with Dr. Lim", "text": "Chapter 3 is fine.\nFix the figures."}; optional project_id, task_id. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'text' => ['nullable', 'string', 'max:200000'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)],
        ]);
        abort_if(trim(($d['title'] ?? '').($d['text'] ?? '')) === '', 422, 'A note needs a title or some text.');
        $note = $user->notes()->create(['title' => $d['title'] ?? '', 'project_id' => $d['project_id'] ?? null] + $this->body(Note::docFromText((string) ($d['text'] ?? ''))));
        if (isset($d['task_id'])) {
            $note->tasks()->attach($d['task_id'], ['created_at' => now()]);
        }

        return $this->ok(ApiPresent::note($note->load(['project', 'event', 'tasks', 'people']), true), 201);
    }

    /** Change the title, replace the text ("text"), add to the end ("append"), pin, or move to a project. */
    public function update(Request $request, Note $note): JsonResponse
    {
        $user = $request->user();
        $this->own($note, $user);
        $d = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'append' => ['sometimes', 'string', 'max:200000'],
            'pinned' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ]);
        if (array_key_exists('title', $d)) {
            $note->title = (string) $d['title'];
        }
        if (array_key_exists('text', $d)) {
            $note->fill($this->body(Note::docFromText((string) $d['text'])));
        }
        if (isset($d['append'])) {
            $note->fill($this->body($this->appended($note, $d['append'])));
        }
        $note->fill(Arr::only($d, ['pinned', 'project_id']));
        $note->save();

        return $this->ok(ApiPresent::note($note->fresh(['project', 'event', 'tasks', 'people']), true));
    }

    /** A day's journal entry (today if no date), or null if there isn't one. */
    public function journal(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->today($user)->toDateString();
        $note = $user->notes()->whereDate('journal_date', $date)->with(['project', 'event', 'tasks', 'people'])->first();

        return $this->ok($note ? ApiPresent::note($note, true) : null);
    }

    /** Adds to a day's journal entry (today if no date), starting it if needed. {"text": "…", "date"?} */
    public function writeJournal(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['text' => ['required', 'string', 'max:200000'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $d['date'] ?? $this->today($user)->toDateString();
        $note = NoteController::openOrStart($user, fn (Builder $b) => $b->whereDate('journal_date', $date), ['journal_date' => $date]);
        $note->fill($this->body($this->appended($note, $d['text'])))->save();

        return $this->ok(ApiPresent::note($note->fresh(['project', 'event', 'tasks', 'people']), true));
    }

    /** The note's document with the text's lines added as paragraphs (an empty last paragraph is filled first). */
    private function appended(Note $note, string $text): array
    {
        $doc = $note->doc();
        $content = $doc['content'] ?? [];
        while ($content && ($last = end($content)) && ($last['type'] ?? '') === 'paragraph' && empty($last['content'])) {
            array_pop($content);
        }
        $doc['content'] = [...$content, ...Note::docFromText(Str::of($text)->trim("\n")->toString())['content']];

        return $doc;
    }

    /** The stored body and its plain words, from a document. */
    private function body(array $doc): array
    {
        return ['body' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'body_text' => Note::textOf($doc)];
    }
}
