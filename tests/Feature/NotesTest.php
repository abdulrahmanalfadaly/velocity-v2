<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\NoteFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Notes: saving the editor's text, search, the journal's one entry a day (in the
 * user's own time zone), class notes, notes left empty, Make task, ownership.
 */
class NotesTest extends TestCase
{
    use RefreshDatabase;

    /** A 1×1 picture. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 12 Nov, 07:00 in Kuala Lumpur, which is still Wednesday 11 Nov in UTC.
        config(['velocity.timezone' => 'Asia/Kuala_Lumpur', 'velocity.fixed_now' => '2026-11-12 07:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    /** "Hello " then "world" in bold, then a list with one item. */
    private function doc(): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'email Dr Lee about the dataset']]]]],
            ]],
        ]];
    }

    private function newNote(array $data = []): Note
    {
        $this->post(route('notes.store'), $data)->assertRedirect();

        return Note::latest('id')->firstOrFail();
    }

    public function test_saving_keeps_the_text_exactly_and_search_finds_words_inside(): void
    {
        $note = $this->newNote();

        $this->putJson(route('notes.save', $note), ['title' => '  Supervisor meeting ', 'body' => json_encode($this->doc())])
            ->assertOk()
            ->assertJsonPath('title', 'Supervisor meeting')
            ->assertJsonPath('preview', 'Hello world email Dr Lee about the dataset');

        $note->refresh();
        // The space before the bold word is kept (form-input trimming never touches the document).
        $this->assertSame('Hello ', $note->doc()['content'][0]['content'][0]['text']);
        $this->assertSame("Hello world\nemail Dr Lee about the dataset", $note->body_text);

        $this->getJson(route('search', ['q' => 'dataset']))
            ->assertJsonPath('groups.0.label', 'Notes')
            ->assertJsonPath('groups.0.items.0.title', 'Supervisor meeting')
            ->assertJsonPath('groups.0.items.0.url', route('notes.index', ['note' => $note->id]));
        $this->get(route('notes.index', ['q' => 'Dr Lee']))->assertInertia(fn ($page) => $page->has('notes', 1));

        $this->putJson(route('notes.save', $note), ['body' => '{"type":"nope"}'])->assertUnprocessable();
    }

    public function test_the_journal_has_one_entry_a_day_in_the_users_own_time_zone(): void
    {
        $this->post(route('notes.journal'))->assertRedirect();
        $entry = Note::firstOrFail();
        $this->assertSame('2026-11-12', $entry->journal_date->toDateString()); // Thursday where the user is, not UTC's Wednesday
        $this->assertSame('Thursday 12 November 2026', $entry->displayTitle());

        // Today again opens the same entry.
        $this->post(route('notes.journal'))->assertRedirect(route('notes.index', ['in' => 'journal', 'note' => $entry->id]));
        $this->assertSame(1, Note::count());

        // Another day is its own entry; journal entries stay out of "All notes".
        $this->post(route('notes.journal'), ['date' => '2026-11-10']);
        $this->get(route('notes.index', ['in' => 'journal']))->assertInertia(fn ($page) => $page
            ->has('notes', 2)
            ->where('notes.0.journalDate', '2026-11-12')
            ->where('journalDays', ['2026-11-10', '2026-11-12']));
        $this->get(route('notes.index'))->assertInertia(fn ($page) => $page->has('notes', 0)->where('menu.journal', 2));

        // A deleted entry makes way for a new one on that day.
        $this->delete(route('notes.destroy', $entry))->assertSessionHas('undo.action', 'restore-note');
        $this->post(route('notes.journal'));
        $this->assertSame(2, Note::count());
        $this->assertNotSame($entry->id, Note::whereDate('journal_date', '2026-11-12')->value('id'));
    }

    public function test_a_class_has_one_note_per_day_named_after_it(): void
    {
        $this->post(route('events.store'), [
            'title' => 'Data Mining', 'calendar' => 'class', 'all_day' => false, 'repeat_weekly' => true,
            'date' => '2026-11-12', 'start_time' => '09:00', 'end_time' => '11:00',
        ])->assertSessionHasNoErrors();
        $event = $this->user->events()->firstOrFail();

        $this->post(route('notes.event'), ['event_id' => $event->id, 'date' => '2026-11-19']);
        $this->post(route('notes.event'), ['event_id' => $event->id, 'date' => '2026-11-19']);
        $this->post(route('notes.event'), ['event_id' => $event->id, 'date' => '2026-11-26']);

        $this->assertSame(2, Note::count());
        $this->get(route('notes.index', ['in' => "e{$event->id}"]))->assertInertia(fn ($page) => $page
            ->where('notes.0.title', 'Data Mining · Thu 26 Nov')
            ->where('notes.1.title', 'Data Mining · Thu 19 Nov')
            ->where('menu.events.0.count', 2));
    }

    public function test_a_note_left_empty_goes_and_one_with_anything_in_it_stays(): void
    {
        $empty = $this->newNote();
        $this->delete(route('notes.discard', $empty))->assertNoContent();
        $this->assertNull(Note::withTrashed()->find($empty->id));

        $titled = $this->newNote(['title' => 'Ideas']);
        $this->delete(route('notes.discard', $titled))->assertNoContent();
        $this->assertNotNull($titled->fresh());

        // Linked to a task, it is kept too even without a word in it.
        $task = $this->user->tasks()->create(['title' => 'Chapter 4', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2]);
        $forTask = $this->newNote(['task_id' => $task->id]);
        $this->assertSame('Chapter 4', $forTask->title);
        $forTask->update(['title' => '']);
        $this->delete(route('notes.discard', $forTask));
        $this->assertNotNull($forTask->fresh());
    }

    public function test_selected_words_become_a_linked_task_in_the_notes_project_and_undo_removes_it(): void
    {
        $project = $this->user->projects()->create(['name' => 'FYP']);
        $note = $this->newNote(['project_id' => $project->id]);

        $this->post(route('notes.make-task', $note), ['title' => "  email Dr Lee\nabout the   dataset "])->assertSessionHasNoErrors();

        $task = $this->user->tasks()->firstOrFail();
        $this->assertSame('email Dr Lee about the dataset', $task->title);
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame([$task->id], $note->tasks()->pluck('tasks.id')->all());
        $undo = session('undo');
        $this->assertSame('delete-task', $undo['action']);

        // The task panel shows the note.
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->where('selected.linkedNotes.0.id', $note->id));

        $this->delete(route('tasks.destroy', $undo['id']));
        $this->assertSame([], $note->tasks()->pluck('tasks.id')->all());
    }

    public function test_pictures_in_a_note_are_private_to_its_owner(): void
    {
        Storage::fake('local');
        $note = $this->newNote(['title' => 'Lab']);

        $res = $this->post(route('note-files.store', $note), ['files' => [UploadedFile::fake()->createWithContent('board.png', base64_decode(self::PNG))], 'inline' => '1'])->assertOk();
        $file = NoteFile::firstOrFail();
        $this->assertTrue($file->inline);
        $this->assertSame("/notes/files/{$file->id}", $res->json('files.0.url'));
        $this->get($res->json('files.0.url'))->assertOk();

        // Inline pictures are in the text, not under Files.
        $this->get(route('notes.index', ['note' => $note->id]))->assertInertia(fn ($page) => $page->has('note.files', 0));

        // Deleting the note for good removes the stored file too.
        $note->forceDelete();
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_no_one_else_can_see_or_change_a_note(): void
    {
        $note = $this->newNote(['title' => 'Private']);
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->putJson(route('notes.save', $note), ['title' => 'Mine now'])->assertNotFound();
        $this->patch(route('notes.update', $note), ['pinned' => true])->assertNotFound();
        $this->delete(route('notes.destroy', $note))->assertNotFound();
        $this->post(route('notes.make-task', $note), ['title' => 'x'])->assertNotFound();
        $this->get(route('notes.index', ['note' => $note->id]))->assertInertia(fn ($page) => $page->where('note', null)->has('notes', 0));
        $this->getJson(route('search', ['q' => 'Private']))->assertJsonPath('groups', []);
        $this->assertSame('Private', $note->fresh()->title);
    }

    public function test_a_deleted_note_waits_in_the_archive_and_comes_back(): void
    {
        $note = $this->user->notes()->create(['title' => 'Viva prep', 'body_text' => 'Slides']);
        $this->delete(route('notes.destroy', $note))->assertSessionHas('undo.action');

        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page
            ->where('notes.0.id', $note->id)->where('notes.0.title', 'Viva prep')->where('notes.0.where', 'Not in a project'));
        $this->post(route('notes.restore', $note->id))->assertSessionHas('toast', '“Viva prep” is back');
        $this->assertFalse($note->fresh()->trashed());
        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page->has('notes', 0));
    }
}
