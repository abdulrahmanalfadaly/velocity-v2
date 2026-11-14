<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Notes linked to other notes, goals and habits ("linked from" on the other side), and tables as text. */
class NoteLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.timezone' => 'Asia/Kuala_Lumpur', 'velocity.fixed_now' => '2026-11-12 07:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function note(string $title): Note
    {
        return $this->user->notes()->create(['title' => $title, 'body' => '', 'body_text' => '']);
    }

    public function test_a_note_links_to_notes_goals_and_habits_and_they_list_it(): void
    {
        $outline = $this->note('Chapter 4 outline');
        $meeting = $this->note('Supervisor meeting');
        $goal = $this->user->goals()->create(['title' => 'Finish the FYP', 'color' => 1]);
        $habit = $this->user->habits()->create(['name' => 'Write 30 min', 'target' => 1]);

        foreach ([['note', $meeting->id], ['goal', $goal->id], ['habit', $habit->id], ['goal', $goal->id]] as [$type, $id]) {
            $this->post(route('notes.links.store', $outline), ['type' => $type, 'id' => $id])->assertSessionHasNoErrors();
        }
        $this->assertSame(3, $outline->links()->count()); // the same link twice is one link

        $this->get(route('notes.index', ['note' => $outline->id]))->assertInertia(fn ($page) => $page
            ->where('note.links.0.title', 'Supervisor meeting')->where('note.links.0.href', route('notes.index', ['note' => $meeting->id]))
            ->where('note.links.1.type', 'goal')->where('note.links.2.title', 'Write 30 min')
            ->where('linkOptions.goal.0.title', 'Finish the FYP'));
        // The other side: "linked from", the goal on the Goals map, the habit's panel.
        $this->get(route('notes.index', ['note' => $meeting->id]))->assertInertia(fn ($page) => $page->where('note.linkedFrom.0.title', 'Chapter 4 outline'));
        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page->where('goals.0.notes.0.title', 'Chapter 4 outline'));
        $this->get(route('habits.index', ['view' => 'all', 'habit' => $habit->id]))->assertInertia(fn ($page) => $page->where('habit.linkedNotes.0.id', $outline->id));

        // Unlink, with Undo.
        $link = $outline->links()->where('linkable_type', 'habit')->sole();
        $this->delete(route('notes.links.destroy', [$outline, $link]))->assertSessionHas('undo', ['action' => 'link-note', 'id' => $outline->id, 'data' => ['type' => 'habit', 'id' => $habit->id]]);
        $this->assertSame(2, $outline->links()->count());
    }

    public function test_only_the_users_own_things_and_never_the_note_itself(): void
    {
        $note = $this->note('Mine');
        $other = User::factory()->create();
        $theirs = $other->goals()->create(['title' => 'Theirs', 'color' => 1]);
        $this->post(route('notes.links.store', $note), ['type' => 'goal', 'id' => $theirs->id])->assertNotFound();
        $this->post(route('notes.links.store', $note), ['type' => 'note', 'id' => $note->id])->assertNotFound();
        $this->post(route('notes.links.store', $note), ['type' => 'task', 'id' => 1])->assertSessionHasErrors('type');

        $theirNote = $other->notes()->create(['title' => 'Theirs', 'body' => '', 'body_text' => '']);
        $link = $theirNote->links()->create(['linkable_type' => 'note', 'linkable_id' => $note->id]);
        $this->delete(route('notes.links.destroy', [$theirNote, $link]))->assertNotFound();
        // Their note links to mine, but it isn't listed to me.
        $this->get(route('notes.index', ['note' => $note->id]))->assertInertia(fn ($page) => $page->where('note.linkedFrom', []));
    }

    public function test_a_note_with_only_a_link_is_kept_and_a_table_reads_row_by_row(): void
    {
        $note = $this->note('');
        $note->links()->create(['linkable_type' => 'goal', 'linkable_id' => $this->user->goals()->create(['title' => 'G', 'color' => 1])->id]);
        $this->assertSame(0, Note::blank()->count());

        $cell = fn (string $t, string $type = 'tableCell') => ['type' => $type, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]];
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Marks']]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [$cell('Part', 'tableHeader'), $cell('Weight', 'tableHeader')]],
                ['type' => 'tableRow', 'content' => [$cell('Report'), $cell('60%')]],
            ]],
        ]];
        $this->putJson(route('notes.save', $note), ['body' => json_encode($doc)])->assertOk();
        $this->assertSame("Marks\nPart | Weight\nReport | 60%", $note->fresh()->body_text);
    }
}
