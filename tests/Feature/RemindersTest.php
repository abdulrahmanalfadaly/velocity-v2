<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reminders: several per task (at a time, before due, Bug me), handed out once when due, snoozed, kept on repeats. */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function task(array $attrs = [], ?User $user = null): Task
    {
        return ($user ?? $this->user)->tasks()->create($attrs + ['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    /** An "at a time" reminder straight in the database (local time). */
    private function at(Task $task, string $at): TaskReminder
    {
        return TaskReminder::create(['task_id' => $task->id, 'user_id' => $task->user_id, 'kind' => 'at', 'at' => $this->local($at), 'next_at' => $this->local($at)]);
    }

    private function due(): array
    {
        return $this->getJson(route('reminders.due'))->assertOk()->json('reminders');
    }

    private function nextAt(TaskReminder $r): ?string
    {
        return $r->fresh()->next_at?->timezone(self::TZ)->format('Y-m-d H:i');
    }

    public function test_reminders_are_added_in_local_time_up_to_five_and_removed_with_undo(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-12 17:00')]);
        $this->post(route('reminders.store', $task), ['kind' => 'at', 'at' => '2026-11-11T15:30'])->assertSessionHas('toast', 'Reminder added');
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '1h']);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '0d']);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '1h'])->assertSessionHasErrors('before');
        $this->post(route('reminders.store', $task), ['kind' => 'bug', 'every' => 60])->assertSessionHas('toast', 'Bugging you about it');
        $this->post(route('reminders.store', $task), ['kind' => 'bug', 'every' => 30])->assertSessionHasErrors('kind');
        $this->post(route('reminders.store', $task), ['kind' => 'bug', 'every' => 45])->assertSessionHasErrors('every');

        $r = $task->reminders()->get();
        $this->assertSame(['at', 'before', 'before', 'bug'], $r->pluck('kind')->all());
        $this->assertSame(['2026-11-11 15:30', '2026-11-12 16:00', '2026-11-12 09:00', '2026-11-11 11:00'], $r->map(fn ($x) => $this->nextAt($x))->all());
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->count('selected.reminders', 4)
            ->where('selected.reminders.1.minutesBefore', 60)->where('selected.reminders.2.daysBefore', 0));

        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '7d']);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '2d'])->assertSessionHasErrors('kind'); // five at most

        $this->delete(route('reminders.destroy', $r[3]))->assertSessionHas('toast', 'Stopped bugging')
            ->assertSessionHas('undo', ['action' => 'restore-reminder', 'id' => $r[3]->id, 'data' => null]);
        $this->assertSame(4, $task->reminders()->count());
        $this->post(route('reminders.restore', $r[3]->id));
        $this->assertSame(5, $task->reminders()->count());

        $other = $this->task([], User::factory()->create());
        $this->post(route('reminders.store', $other), ['kind' => 'at', 'at' => '2026-11-11T15:30'])->assertNotFound();
        $this->delete(route('reminders.destroy', $this->at($other, '2026-11-11 15:00')))->assertNotFound();
    }

    public function test_due_reminders_are_handed_out_once_and_only_for_open_tasks(): void
    {
        $now = $this->task(['title' => 'Now', 'due_at' => $this->local('2026-11-11 12:00')]);
        $first = $this->at($now, '2026-11-11 09:55');
        $this->at($this->task(['title' => 'Later today']), '2026-11-11 15:00');
        $this->at($this->task(['title' => 'Done one', 'status' => 'done']), '2026-11-11 08:00');
        $this->at($this->task(['title' => 'Theirs'], User::factory()->create()), '2026-11-11 08:00');

        $shown = $this->due();
        $this->assertSame(['Now'], array_column($shown, 'title'));
        $this->assertSame([$first->id, $now->id, 'at', '2026-11-11T04:00:00Z'], [$shown[0]['id'], $shown[0]['taskId'], $shown[0]['kind'], $shown[0]['dueAt']]);
        $this->assertSame([], $this->due()); // shown once

        // The API's single "reminder_at" field: one "at a time" reminder at the new time, which shows again.
        $this->patch(route('tasks.update', $now), ['reminder_at' => '2026-11-11T09:59']);
        $this->assertSame(1, $now->reminders()->count());
        $this->assertSame(['Now'], array_column($this->due(), 'title'));
        $this->patch(route('tasks.update', $now), ['reminder_at' => null]);
        $this->assertSame(0, $now->reminders()->count());
    }

    public function test_before_due_reminders_move_with_the_due_date(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-12 17:00')]);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '1h']);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '1d']);
        [$hour, $day] = $task->reminders()->get()->all();
        $this->assertSame(['2026-11-12 16:00', '2026-11-11 09:00'], [$this->nextAt($hour), $this->nextAt($day)]);
        $this->assertSame([$day->id], array_column($this->due(), 'id')); // 9:00 today has passed

        // Moved by the selection bar: both follow, and "1 day before" shows again for its new time.
        $this->post(route('tasks.bulk'), ['ids' => [$task->id], 'action' => 'due', 'due_at' => '2026-11-14T17:00']);
        $this->assertSame(['2026-11-14 16:00', '2026-11-13 09:00'], [$this->nextAt($hour), $this->nextAt($day)]);
        config(['velocity.fixed_now' => '2026-11-13 09:01']);
        $this->assertSame([$day->id], array_column($this->due(), 'id'));

        // No due date: they wait.
        $this->patch(route('tasks.update', $task), ['due_at' => null]);
        $this->assertSame([null, null], [$this->nextAt($hour), $this->nextAt($day)]);
    }

    public function test_bug_me_comes_back_until_the_task_is_done_or_stopped(): void
    {
        $task = $this->task();
        $this->post(route('reminders.store', $task), ['kind' => 'bug', 'every' => 30]);
        $bug = $task->reminders()->sole();
        // The first one comes after the first 30 minutes, not the moment it is switched on.
        $this->assertSame([], $this->due());
        $this->assertSame('2026-11-11 10:30', $this->nextAt($bug));
        config(['velocity.fixed_now' => '2026-11-11 10:30']);
        $this->assertSame([$bug->id], array_column($this->due(), 'id'));
        $this->assertSame([], $this->due());
        $this->assertSame('2026-11-11 11:00', $this->nextAt($bug));

        // Missed ones never pile up: an hour and a half later it shows once, then waits another 30 minutes.
        config(['velocity.fixed_now' => '2026-11-11 12:31']);
        $this->assertSame([$bug->id], array_column($this->due(), 'id'));
        $this->assertSame('2026-11-11 13:01', $this->nextAt($bug));

        $this->post(route('tasks.complete', $task));
        config(['velocity.fixed_now' => '2026-11-11 14:00']);
        $this->assertSame([], $this->due());
        $this->post(route('tasks.reopen', $task));
        $this->assertSame([$bug->id], array_column($this->due(), 'id')); // back with the task

        $this->delete(route('reminders.destroy', $bug));
        config(['velocity.fixed_now' => '2026-11-11 15:00']);
        $this->assertSame([], $this->due());
    }

    public function test_snooze_reminds_again_later_or_at_a_time_picked(): void
    {
        $task = $this->task();
        $r = $this->at($task, '2026-11-11 09:59');
        $this->due();
        $this->post(route('reminders.snooze', $r), ['minutes' => 10])->assertSessionHas('toast', 'Reminder in 10 min');
        $this->assertSame([], $this->due()); // not yet

        config(['velocity.fixed_now' => '2026-11-11 10:11']);
        $this->assertSame([$r->id], array_column($this->due(), 'id'));
        $this->post(route('reminders.snooze', $r), ['until' => '2026-11-12T09:00'])->assertSessionHas('toast', 'Reminder Thu 12 Nov, 9:00 am');
        $this->assertSame('2026-11-12 09:00', $r->fresh()->at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->post(route('reminders.snooze', $r), ['until' => '2026-11-11T08:00'])->assertSessionHasErrors('until');
        $this->post(route('reminders.snooze', $r), ['minutes' => 0])->assertSessionHasErrors('minutes');

        $theirs = $this->at($this->task([], User::factory()->create()), '2026-11-11 08:00');
        $this->post(route('reminders.snooze', $theirs), ['minutes' => 10])->assertNotFound();
    }

    public function test_the_next_copy_of_a_repeating_task_brings_its_reminders_along(): void
    {
        $task = $this->task(['repeat_unit' => 'week', 'due_at' => $this->local('2026-11-16 09:00')]);
        $this->at($task, '2026-11-16 08:00')->update(['sent_at' => now()]);
        $this->post(route('reminders.store', $task), ['kind' => 'before', 'before' => '1d']);

        $this->post(route('tasks.complete', $task));
        $next = Task::where('repeated_from_id', $task->id)->firstOrFail();
        $copies = $next->reminders()->get();
        $this->assertSame(['at', 'before'], $copies->pluck('kind')->all());
        $this->assertSame(['2026-11-23 08:00', '2026-11-22 09:00'], $copies->map(fn ($x) => $this->nextAt($x))->all());
        $this->assertNull($copies[0]->sent_at);
    }
}
