<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 11: "Capture only" puts a task in the Inbox's To sort, out of the ranking, until "Rank it". */
class CaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_captured_task_waits_unranked_until_ranked(): void
    {
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($user);
        $user->tasks()->create(['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);

        $this->post(route('tasks.store'), ['text' => 'Idea for the demo video', 'capture' => true])
            ->assertSessionHas('toast', 'Captured “Idea for the demo video” · in the Inbox to sort');
        $idea = Task::where('title', 'Idea for the demo video')->sole();
        $this->assertNotNull($idea->captured_at);

        // Not in the ranking and not "not ready": only in To sort, and counted there.
        $this->get(route('tasks.inbox'))->assertRedirect(route('tasks.index', ['show' => 'inbox']));
        $this->get(route('tasks.index', ['show' => 'inbox']))->assertInertia(fn (Assert $p) => $p
            ->where('show', 'inbox')
            ->has('tasks', 1)
            ->where('captured.0.title', 'Idea for the demo video')
            ->where('notReady', [])
            ->where('nav.counts.captured', 1)
            ->where('nav.counts.waiting', 0));

        $this->post(route('tasks.rank', $idea))->assertSessionHas('toast', fn ($t) => str_starts_with($t, '“Idea for the demo video” is ranked · #'));
        $this->assertNull($idea->fresh()->captured_at);
        $this->get(route('tasks.index', ['show' => 'inbox']))->assertInertia(fn (Assert $p) => $p->has('tasks', 2)->where('captured', []));

        // Undo puts it back in To sort.
        $this->post(route('tasks.capture', $idea))->assertSessionHas('toast', '“Idea for the demo video” is back in To sort');
        $this->assertNotNull($idea->fresh()->captured_at);
    }
}
