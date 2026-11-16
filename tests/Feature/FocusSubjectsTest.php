<?php

namespace Tests\Feature;

use App\Models\FocusSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 11: focus on a project, goal, habit or note, and a timer that stops when its time is up. */
class FocusSubjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('10:00:00');
        config(['velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    /** Moves the app's clock (Kuala Lumpur time, Wed 11 Nov 2026). */
    private function at(string $time): void
    {
        config(['velocity.fixed_now' => "2026-11-11 {$time}"]);
    }

    public function test_focus_on_a_project_counts_for_the_project_and_carries_into_the_next_round(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $this->post(route('focus.start'), ['project_id' => $fyp->id, 'mode' => 'pomodoro'])->assertSessionHas('toast', 'Focus on “FYP”');
        $this->get(route('home'))->assertInertia(fn (Assert $p) => $p
            ->where('focusSession.subject', ['kind' => 'project', 'id' => $fyp->id, 'title' => 'FYP', 'href' => "/projects/{$fyp->id}"])
            ->where('focusSession.taskId', null));

        // The round ends; the next one is on the project too.
        $this->at('10:25:00');
        $this->get(route('home'));
        $first = FocusSession::sole();
        $this->post(route('focus.next', $first));
        $this->assertSame($fyp->id, FocusSession::whereNull('ended_at')->sole()->project_id);

        $this->get("/projects/{$fyp->id}")->assertInertia(fn (Assert $p) => $p->where('stats.focusedWeek', 25 * 60));

        // Someone else's project can't be focused on.
        $theirs = User::factory()->create()->projects()->create(['name' => 'Theirs']);
        $this->post(route('focus.start'), ['project_id' => $theirs->id])->assertSessionHasErrors('project_id');
    }

    public function test_after_focus_on_a_habit_it_offers_to_tick_it_off(): void
    {
        $read = $this->user->habits()->create(['name' => 'Read', 'kind' => 'minutes', 'target' => 30]);
        $this->post(route('focus.start'), ['habit_id' => $read->id, 'minutes' => 25]);
        $this->at('10:25:00');
        $this->post(route('focus.stop', FocusSession::sole()))
            ->assertSessionHas('focusHabit', ['habitId' => $read->id, 'name' => 'Read', 'minutes' => 25, 'count' => 25, 'doneAlready' => false]);

        $stretch = $this->user->habits()->create(['name' => 'Stretch', 'kind' => 'do', 'target' => 1]);
        $this->post(route('focus.start'), ['habit_id' => $stretch->id]);
        $this->at('10:40:00');
        $this->post(route('focus.stop', FocusSession::whereNull('ended_at')->sole()))
            ->assertSessionHas('focusHabit', fn ($o) => $o['minutes'] === null && $o['count'] === 1);

        // A goal and a note can be focused on too; only one thing at a time (the first given).
        $goal = $this->user->goals()->create(['title' => 'Graduate with a First']);
        $this->post(route('focus.start'), ['goal_id' => $goal->id, 'habit_id' => $read->id]);
        $s = FocusSession::whereNull('ended_at')->sole();
        $this->assertSame([$goal->id, null], [$s->goal_id, $s->habit_id]);
        $this->assertSame('Graduate with a First', $s->subject()['title']);
    }

    public function test_a_timer_set_to_stop_stops_at_its_end(): void
    {
        $this->user->forceFill(['focus_settings' => ['timeUp' => 'stop']])->save();
        $task = $this->user->tasks()->create(['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2]);
        $this->post(route('focus.start'), ['task_id' => $task->id, 'minutes' => 30]);

        // Stopped by the open window a little after the end: counted as exactly 30 minutes.
        $this->at('10:30:40');
        $this->post(route('focus.stop', FocusSession::sole()));
        $this->assertSame(30 * 60, FocusSession::sole()->seconds);

        // Left running while Velocity was closed: the server stops it at its end.
        $this->post(route('focus.start'), ['task_id' => $task->id, 'minutes' => 20]);
        $this->at('12:00:00');
        $this->get(route('home'))->assertInertia(fn (Assert $p) => $p->where('focusSession', null));
        $this->assertSame(20 * 60, FocusSession::latest('id')->first()->seconds);

        $this->get('/settings/focus')->assertInertia(fn (Assert $p) => $p->where('settings.timeUp', 'stop'));
    }
}
