<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reminders: set in the panel, handed out once when due, snoozed, kept on repeats. */
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

    private function task(array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function due(): array
    {
        return $this->getJson(route('reminders.due'))->assertOk()->json('reminders');
    }

    public function test_a_reminder_is_set_in_the_panel_in_local_time_and_can_be_undone(): void
    {
        $task = $this->task();
        $this->patch(route('tasks.update', $task), ['reminder_at' => '2026-11-11T09:30'])->assertSessionHas('toast', 'Reminder changed');
        $undo = session('undo');
        $this->assertSame('2026-11-11 09:30', $task->fresh()->reminder_at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->where('selected.reminderAt', '2026-11-11T01:30:00Z'));

        $this->post(route('tasks.revert', $task), $undo['data']);
        $this->assertNull($task->fresh()->reminder_at);
    }

    public function test_due_reminders_are_handed_out_once_and_only_for_open_tasks(): void
    {
        $now = $this->task(['title' => 'Now', 'reminder_at' => CarbonImmutable::parse('2026-11-11 09:55', self::TZ)->utc(), 'due_at' => CarbonImmutable::parse('2026-11-11 12:00', self::TZ)->utc()]);
        $this->task(['title' => 'Later today', 'reminder_at' => CarbonImmutable::parse('2026-11-11 15:00', self::TZ)->utc()]);
        $this->task(['title' => 'Done one', 'status' => 'done', 'reminder_at' => CarbonImmutable::parse('2026-11-11 08:00', self::TZ)->utc()]);
        User::factory()->create()->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'reminder_at' => now()->subHour()]);

        $first = $this->due();
        $this->assertSame(['Now'], array_column($first, 'title'));
        $this->assertSame('2026-11-11T04:00:00Z', $first[0]['dueAt']);
        $this->assertSame([], $this->due()); // shown once

        // Changing the time makes it pop up again when that time comes.
        $this->patch(route('tasks.update', $now), ['reminder_at' => '2026-11-11T09:59']);
        $this->assertSame(['Now'], array_column($this->due(), 'title'));
    }

    public function test_snooze_reminds_again_later(): void
    {
        $task = $this->task(['reminder_at' => now()->subMinute()]);
        $this->due();
        $this->post(route('tasks.snooze', $task), ['minutes' => 10])->assertSessionHas('toast', 'Reminder in 10 min');
        $this->assertSame([], $this->due()); // not yet

        config(['velocity.fixed_now' => '2026-11-11 10:11']);
        $this->assertSame([$task->id], array_column($this->due(), 'id'));
        $this->post(route('tasks.snooze', $task), ['minutes' => 0])->assertSessionHasErrors('minutes');
        $other = User::factory()->create()->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $this->post(route('tasks.snooze', $other), ['minutes' => 10])->assertNotFound();
    }

    public function test_the_next_copy_of_a_repeating_task_brings_its_reminder_along(): void
    {
        $task = $this->task([
            'repeat_unit' => 'week',
            'due_at' => CarbonImmutable::parse('2026-11-16 09:00', self::TZ)->utc(),
            'reminder_at' => CarbonImmutable::parse('2026-11-16 08:00', self::TZ)->utc(),
            'reminder_sent_at' => now(),
        ]);
        $this->post(route('tasks.complete', $task));
        $next = Task::where('repeated_from_id', $task->id)->firstOrFail();
        $this->assertSame('2026-11-23 08:00', $next->reminder_at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertNull($next->reminder_sent_at);
    }
}
