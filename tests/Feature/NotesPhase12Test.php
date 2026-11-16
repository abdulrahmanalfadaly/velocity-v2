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

    public function test_a_note_can_be_in_several_projects_and_an_area(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $dm = $this->user->projects()->create(['name' => 'Data Mining']);
        $uni = $this->user->areas()->create(['name' => 'University']);
        $note = $this->user->notes()->create(['title' => 'Survey method', 'body_text' => 'x']);

        $this->patch(route('notes.update', $note), ['project_ids' => [$fyp->id, $dm->id]])->assertSessionHas('toast', 'In 2 projects');
        $this->assertSame([$fyp->id, $dm->id], $note->fresh()->projectIds());
        $this->assertSame($fyp->id, $note->fresh()->project_id);
        $this->get(route('notes.index', ['in' => "p{$dm->id}"]))->assertInertia(fn (Assert $p) => $p
            ->where('notes.0.id', $note->id)
            ->where('menu.projects', [['id' => $dm->id, 'name' => 'Data Mining', 'count' => 1], ['id' => $fyp->id, 'name' => 'FYP', 'count' => 1]]));
        $this->get("/projects/{$dm->id}")->assertInertia(fn (Assert $p) => $p->where('notes.0.title', 'Survey method'));

        $this->patch(route('notes.update', $note), ['area_id' => $uni->id])->assertSessionHas('toast', 'Moved to University');
        $this->get(route('notes.index', ['in' => "a{$uni->id}"]))->assertInertia(fn (Assert $p) => $p
            ->where('notes.0.id', $note->id)
            ->where('menu.areas.0.count', 1));

        // Deleting its first project: the next one becomes its first; Undo puts it back.
        $this->delete(route('projects.destroy', $fyp), ['tasks' => 'inbox']);
        $this->assertSame([$dm->id], $note->fresh()->projectIds());
        $this->post(route('projects.restore', $fyp->id), session('undo')['data']);
        $this->assertEqualsCanonicalizing([$fyp->id, $dm->id], $note->fresh()->projectIds());
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
