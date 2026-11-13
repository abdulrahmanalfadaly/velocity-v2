<?php

namespace Tests\Feature;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The focus timer: time from timestamps, pauses, stop and the "hours left?" question, undo. */
class FocusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('10:00:00');
        config(['velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
        $this->task = $this->user->tasks()->create(['title' => 'Chapter 4', 'importance' => 4, 'urgency' => 3, 'est_hours' => 3]);
    }

    /** Moves the app's clock (Kuala Lumpur time, Wed 11 Nov 2026). */
    private function at(string $time): void
    {
        config(['velocity.fixed_now' => "2026-11-11 {$time}"]);
    }

    private function running(): ?FocusSession
    {
        return FocusSession::whereNull('ended_at')->first();
    }

    public function test_focus_on_a_task_marks_it_doing_and_shows_the_timer(): void
    {
        $this->post(route('focus.start'), ['task_id' => $this->task->id, 'minutes' => 50])
            ->assertSessionHas('toast', 'Focus on “Chapter 4”')
            ->assertSessionHas('undo', fn ($u) => $u['action'] === 'focus-cancel');
        $this->assertSame('doing', $this->task->fresh()->status);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page
            ->where('focusSession.taskTitle', 'Chapter 4')
            ->where('focusSession.plannedMinutes', 50)
            ->where('focusSession.startedAt', '2026-11-11T02:00:00Z'));
    }

    public function test_time_is_counted_from_timestamps_with_pauses_left_out(): void
    {
        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $s = $this->running();
        $this->at('10:20:00');
        $this->post(route('focus.pause', $s));
        $this->at('10:35:00'); // 15 minutes paused
        $this->post(route('focus.resume', $s));
        $this->at('10:45:00');
        $this->assertSame(30 * 60, $s->fresh()->elapsed(app(Clock::class)->now()));

        $this->post(route('focus.stop', $s))->assertSessionHas('focusDone', fn ($d) => $d['seconds'] === 1800 && $d['hoursLeft'] === 2.5 && $d['estHours'] === 3.0);
        $this->assertSame(1800, $s->fresh()->seconds);
        // The estimate only changes if the user says so.
        $this->assertSame(3.0, $this->task->fresh()->est_hours);
        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($page) => $page
            ->where('focusSession', null)
            ->where('selected.focused', ['seconds' => 1800, 'sessions' => 1]));
    }

    public function test_stopping_while_paused_counts_up_to_the_pause_and_false_starts_are_dropped(): void
    {
        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $s = $this->running();
        $this->at('10:10:00');
        $this->post(route('focus.pause', $s));
        $this->at('11:00:00');
        $this->post(route('focus.stop', $s));
        $this->assertSame(600, $s->fresh()->seconds);

        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $this->at('11:00:30');
        $this->post(route('focus.stop', $this->running()))->assertSessionHas('toast', 'Under a minute – not counted');
        $this->assertSame(1, FocusSession::count());
    }

    public function test_starting_again_finishes_the_one_running_and_undo_works_both_ways(): void
    {
        $other = $this->user->tasks()->create(['title' => 'Slides', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => 'waiting']);
        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $this->at('10:25:00');
        $this->post(route('focus.start'), ['task_id' => $other->id]);
        $this->assertSame(1500, FocusSession::where('task_id', $this->task->id)->value('seconds'));
        $this->assertSame($other->id, $this->running()->task_id);

        // Undo of Start: gone, and the task is Waiting again.
        $this->post(route('focus.cancel', $this->running()));
        $this->assertNull($this->running());
        $this->assertSame('waiting', $other->fresh()->status);

        // Undo of Stop: carries on, the moments in between count as a pause.
        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $s = $this->running();
        $this->at('10:45:00');
        $this->post(route('focus.stop', $s));
        $this->at('10:45:05');
        $this->post(route('focus.undo-stop', $s));
        $this->at('10:55:00');
        $this->assertSame(1800 - 5, $s->fresh()->elapsed(app(Clock::class)->now())); // 10:25–10:55, minus the 5 s stopped
    }

    public function test_project_shows_time_focused_this_week_and_others_cannot_touch_a_session(): void
    {
        $project = $this->user->projects()->create(['name' => 'FYP']);
        $this->task->update(['project_id' => $project->id]);
        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $this->at('11:00:00');
        $this->post(route('focus.stop', $this->running() ?? FocusSession::first()));
        $this->get(route('projects.show', $project))->assertInertia(fn ($page) => $page->where('stats.focusedWeek', 3600));

        $this->post(route('focus.start'), ['task_id' => $this->task->id]);
        $s = $this->running();
        $this->actingAs(User::factory()->create());
        $this->post(route('focus.stop', $s))->assertNotFound();
        $this->post(route('focus.start'), ['task_id' => $this->task->id])->assertSessionHasErrors('task_id');
    }
}
