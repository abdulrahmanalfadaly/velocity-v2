<?php

namespace Tests\Feature;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pomodoro rounds and breaks, Focus settings, intention and note, "Task done" in the timer, and Review → Focus. */
class FocusPomodoroTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function at(string $time): void
    {
        config(['velocity.fixed_now' => "2026-11-11 {$time}"]);
    }

    private function task(array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => 'Chapter 4', 'importance' => 3, 'urgency' => 3, 'est_hours' => 3]);
    }

    /** The timer as the top bar gets it. */
    private function timer(): ?array
    {
        $timer = null;
        $this->get(route('home'))->assertInertia(function ($page) use (&$timer) {
            $timer = $page->toArray()['props']['focusSession'] ?? null;
        });

        return $timer;
    }

    private function settings(array $change): void
    {
        $this->put(route('focus-settings.update'), $change + [
            'mode' => 'timer', 'length' => 50, 'focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4,
            'autoBreak' => true, 'autoNext' => false, 'sound' => true,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_round_stops_at_its_length_then_the_break_and_the_next_round(): void
    {
        $task = $this->task();
        $this->post(route('focus.start'), ['task_id' => $task->id, 'mode' => 'pomodoro', 'intention' => 'Draft 4.2']);
        $t = $this->timer();
        $this->assertSame(['focus', 'pomodoro', 1, 25, 'Draft 4.2'], [$t['phase'], $t['mode'], $t['round'], $t['plannedMinutes'], $t['intention']]);

        // Away for 26 minutes: the round ended at 25 (not counted past it) and the break runs.
        $this->at('10:26');
        $t = $this->timer();
        $this->assertSame(['break', 5, '2026-11-11T02:30:00Z'], [$t['phase'], $t['breakMinutes'], $t['breakEndsAt']]);
        $round = FocusSession::sole();
        $this->assertSame([1500, '2026-11-11 02:25'], [$round->seconds, $round->ended_at->format('Y-m-d H:i')]);

        // The break is over: it waits for the next round (it doesn't start by itself).
        $this->at('10:31');
        $this->assertSame('breakOver', $this->timer()['phase']);
        $this->post(route('focus.next', $round))->assertSessionHas('toast', 'Round 2');
        $t = $this->timer();
        $this->assertSame(['focus', 2, $task->id, 'Draft 4.2'], [$t['phase'], $t['round'], $t['taskId'], $t['intention']]);
    }

    public function test_every_fourth_round_has_the_long_break_and_done_ends_it(): void
    {
        $this->post(route('focus.start'), ['mode' => 'pomodoro']);
        foreach (['10:25' => 1, '10:55' => 2, '11:25' => 3] as $end => $n) {
            $this->at($end);
            $this->timer();
            $this->post(route('focus.next', FocusSession::latest('id')->first()));
        }
        $this->at('11:50');
        $t = $this->timer();
        $this->assertSame(['break', 4, 15], [$t['phase'], $t['round'], $t['breakMinutes']]);

        $this->post(route('focus.end', $t['id']))->assertSessionHas('toast', 'Pomodoro done · 4 rounds');
        $this->assertNull($this->timer());
        $this->assertSame(4, FocusSession::count());
    }

    public function test_settings_decide_whether_breaks_and_rounds_start_by_themselves(): void
    {
        $this->settings(['autoBreak' => false, 'autoNext' => true, 'mode' => 'pomodoro', 'focus' => 20, 'short' => 10]);
        // "Start focus" with nothing else starts what the settings say.
        $this->post(route('focus.start'));
        $this->assertSame(['pomodoro', 20], [$this->timer()['mode'], $this->timer()['plannedMinutes']]);

        $this->at('10:21');
        $t = $this->timer();
        $this->assertSame('roundDone', $t['phase']);
        $this->post(route('focus.break', $t['id']));
        $t = $this->timer();
        $this->assertSame(['break', '2026-11-11T02:31:00Z'], [$t['phase'], $t['breakEndsAt']]);

        // Back just after the break: the next round started when the break ended.
        $this->at('10:32');
        $t = $this->timer();
        $this->assertSame(['focus', 2, '2026-11-11T02:31:00Z'], [$t['phase'], $t['round'], $t['startedAt']]);

        // Left alone for hours after a round: the Pomodoro is over.
        $this->at('15:00');
        $this->timer();
        $this->assertNull($this->timer());

        $this->put(route('focus-settings.update'), ['mode' => 'timer', 'length' => 300])->assertSessionHasErrors(['length', 'focus', 'sound']);
        $this->get(route('focus-settings.edit'))->assertInertia(fn ($page) => $page->component('settings/focus')->where('settings.focus', 20));
    }

    public function test_a_plain_timer_keeps_counting_past_its_time(): void
    {
        $this->post(route('focus.start'), ['minutes' => 25]);
        $this->at('10:40');
        $t = $this->timer();
        $this->assertSame(['focus', 'timer'], [$t['phase'], $t['mode']]);
        $this->post(route('focus.stop', $t['id']));
        $this->assertSame(40 * 60, FocusSession::sole()->seconds);
    }

    public function test_task_done_in_the_timer_keeps_the_focus_and_ticks_the_task(): void
    {
        $task = $this->task();
        $this->post(route('focus.start'), ['task_id' => $task->id, 'minutes' => 50]);
        $this->at('10:30');
        $id = $this->timer()['id'];
        $this->post(route('focus.done-task', $id))->assertSessionHas('toast', 'Done: “Chapter 4” · focused 30 min')
            ->assertSessionHas('undo', ['action' => 'reopen', 'id' => $task->id, 'data' => ['undo' => true]]);
        $this->assertSame([Task::DONE, 1800], [$task->fresh()->status, FocusSession::sole()->seconds]);
        $this->assertNull($this->timer());
    }

    public function test_the_note_after_and_removing_a_session_from_the_history(): void
    {
        $task = $this->task();
        $this->post(route('focus.start'), ['task_id' => $task->id, 'minutes' => 50]);
        $this->at('10:50');
        $this->post(route('focus.stop', $this->timer()['id']))->assertSessionHas('focusDone.sessionId');
        $session = FocusSession::sole();
        $this->patch(route('focus.update', $session), ['note' => '  Wrote 4.2  ']);
        $this->assertSame('Wrote 4.2', $session->fresh()->note);

        $this->delete(route('focus.destroy', $session))->assertSessionHas('undo', ['action' => 'focus-restore', 'id' => $session->id, 'data' => null]);
        $this->assertSoftDeleted($session);
        $this->get(route('review.index', ['view' => 'focus']))->assertInertia(fn ($page) => $page->where('focus.count', 0));
        $this->post(route('focus.restore', $session->id));
        $this->assertNotSoftDeleted($session);

        $other = User::factory()->create()->focusSessions()->create(['started_at' => now(), 'ended_at' => now(), 'seconds' => 600]);
        $this->delete(route('focus.destroy', $other))->assertNotFound();
    }

    public function test_review_focus_spreads_each_session_over_the_hours_it_ran(): void
    {
        // 9:30–10:30 with 20 minutes paused: 40 minutes, split evenly over the two hours.
        $this->user->focusSessions()->create([
            'started_at' => '2026-11-11 01:30:00', 'ended_at' => '2026-11-11 02:30:00', 'paused_seconds' => 1200, 'seconds' => 2400, 'intention' => 'Draft',
        ]);
        $this->get(route('review.index', ['view' => 'focus']))->assertInertia(fn ($page) => $page->component('review/index')
            ->where('focus.range', 'week')->where('focus.start', '2026-11-09')->where('focus.total', 40)->where('focus.count', 1)
            ->where('focus.hours.9', 20)->where('focus.hours.10', 20)->where('focus.weekdays.2', 40)
            ->where('focus.sessions.0.intention', 'Draft'));
        $this->get(route('review.index', ['view' => 'focus', 'range' => 'month', 'date' => '2026-10-15']))
            ->assertInertia(fn ($page) => $page->where('focus.start', '2026-10-01')->where('focus.count', 0));
    }
}
