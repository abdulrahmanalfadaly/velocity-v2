<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
