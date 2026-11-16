<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 12 in Notes: drawings, the jump back from a task, archiving, several projects and areas. */
class NotesPhase12Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    public function test_a_task_made_from_a_note_remembers_the_words_to_jump_back_to(): void
    {
        $note = $this->user->notes()->create(['title' => 'Chapter 4 outline', 'body_text' => 'Add the weights section']);
        $this->post(route('notes.make-task', $note), ['title' => '  Add the   weights section '])->assertSessionHasNoErrors();
        $task = $this->user->tasks()->where('title', 'Add the weights section')->sole();

        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn (Assert $p) => $p
            ->where('selected.linkedNotes.0', ['id' => $note->id, 'title' => 'Chapter 4 outline', 'quote' => 'Add the weights section']));
    }

    public function test_an_archived_note_is_read_only_and_out_of_the_lists(): void
    {
        $note = $this->user->notes()->create(['title' => 'Old meeting', 'body_text' => 'Agenda']);
        $this->post(route('notes.archive', $note))->assertSessionHas('toast', 'Archived “Old meeting”');

        $this->get(route('notes.index'))->assertInertia(fn (Assert $p) => $p->where('notes', [])->where('menu.archived', 1));
        $this->get(route('notes.index', ['in' => 'archived', 'note' => $note->id]))->assertInertia(fn (Assert $p) => $p
            ->where('notes.0.id', $note->id)
            ->where('note.archivedAt', fn ($v) => $v !== null));
        $this->putJson(route('notes.save', $note), ['title' => 'Changed'])->assertStatus(422);
        $this->assertSame('Old meeting', $note->fresh()->title);

        $this->post(route('notes.unarchive', $note));
        $this->assertNull($note->fresh()->archived_at);
    }

    public function test_a_note_holding_only_a_drawing_is_kept(): void
    {
        $note = $this->user->notes()->create(['title' => '', 'body' => null, 'body_text' => '']);
        $doc = ['type' => 'doc', 'content' => [['type' => 'drawing', 'attrs' => ['height' => 300, 'strokes' => [['t' => 'pen', 'c' => 'ink', 'w' => 3, 'p' => [10, 10, 40, 60]]]]]]];
        $this->putJson(route('notes.save', $note), ['body' => json_encode($doc)])->assertOk();

        $this->assertSame('(drawing)', $note->fresh()->body_text);
        $this->delete(route('notes.discard', $note))->assertNoContent();
        $this->assertNotNull(Note::find($note->id));
        $this->assertSame([10, 10, 40, 60], json_decode($note->fresh()->body, true)['content'][0]['attrs']['strokes'][0]['p']);
    }
}
