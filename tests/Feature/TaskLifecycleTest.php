<?php

namespace Tests\Feature;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cancel with a reason (and skipping one repeat), archiving finished tasks, follow-ups and "waiting since". */
class TaskLifecycleTest extends TestCase
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

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    public function test_cancelling_keeps_the_reason_frees_waiting_tasks_and_reopen_puts_it_back(): void
    {
        $task = $this->task(['status' => Task::DOING]);
        $after = $this->task(['title' => 'Slides']);
        $after->dependencies()->sync([$task->id]);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('tasks', 1));

        $this->post(route('tasks.cancel', $task), ['reason' => 'Supervisor dropped it'])
            ->assertSessionHas('toast', 'Cancelled “Essay” · “Slides” can start now')->assertSessionHas('undo', ['action' => 'reopen', 'id' => $task->id]);
        $task->refresh();
        $this->assertSame([Task::CANCELLED, 'Supervisor dropped it'], [$task->status, $task->cancel_reason]);
        $this->assertNotNull($task->cancelled_at);
        // It leaves the list; the task that waited for it can start now.
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('tasks', 1)->where('tasks.0.id', $after->id));
        // A cancelled task can't be ticked off or have its status changed.
        $this->patch(route('tasks.update', $task), ['status' => 'todo']);
        $this->assertSame(Task::CANCELLED, $task->fresh()->status);

        $this->post(route('tasks.reopen', $task))->assertSessionHas('toast', 'Back: “Essay” · “Slides” waits on it again')
            ->assertSessionHas('undo', ['action' => 'cancel-task', 'id' => $task->id, 'data' => ['reason' => 'Supervisor dropped it', 'skip' => false]]);
        $task->refresh();
        $this->assertSame([Task::DOING, null, null], [$task->status, $task->cancel_reason, $task->cancelled_at]);
    }

    public function test_cancelling_stops_a_running_focus_on_the_task(): void
    {
        $task = $this->task();
        $this->post(route('focus.start'), ['task_id' => $task->id, 'minutes' => 25]);
        config(['velocity.fixed_now' => '2026-11-11 10:10']);
        $this->post(route('tasks.cancel', $task));
        $this->assertNotNull(FocusSession::sole()->ended_at);
    }

    public function test_skipping_a_repeating_task_makes_the_next_one_and_undo_takes_it_back(): void
    {
        $task = $this->task(['title' => 'Gym', 'due_at' => $this->local('2026-11-11 18:00'), 'repeat_unit' => 'week', 'repeat_every' => 1]);

        $this->post(route('tasks.cancel', $task), ['skip' => true])->assertSessionHas('toast', 'Skipped “Gym” · next one Wed 18 Nov');
        $this->assertSame('Skipped', $task->fresh()->cancel_reason);
        $next = Task::where('repeated_from_id', $task->id)->sole();
        $this->assertSame('2026-11-18 10:00', $next->due_at->format('Y-m-d H:i'));

        // Stop repeating instead: no next copy.
        $this->post(route('tasks.reopen', $task))->assertSessionHas('undo.data.skip', true);
        $this->assertSoftDeleted($next);
        $this->post(route('tasks.cancel', $task), ['skip' => false]);
        $this->assertSame(0, Task::where('repeated_from_id', $task->id)->count());
    }

    public function test_only_finished_tasks_can_be_archived_and_archived_ones_leave_the_lists(): void
    {
        $open = $this->task(['title' => 'Open one']);
        $done = $this->task(['title' => 'Old essay']);
        $this->post(route('tasks.complete', $done));

        $this->post(route('tasks.archive-one', $open));
        $this->assertNull($open->fresh()->archived_at);
        $this->post(route('tasks.archive-one', $done))->assertSessionHas('toast', 'Archived “Old essay”')
            ->assertSessionHas('undo', ['action' => 'unarchive-task', 'id' => $done->id, 'data' => null]);
        $this->assertNotNull($done->fresh()->archived_at);

        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('doneToday', 0));
        $this->get(route('search', ['q' => 'essay']))->assertOk()->assertDontSee('Old essay');
        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page->where('archived.0.id', $done->id)->where('archived.0.status', 'done'));

        // Reopening takes it out of the archive too.
        $this->post(route('tasks.unarchive', $done));
        $this->assertNull($done->fresh()->archived_at);
        $this->post(route('tasks.archive-one', $done));
        $this->post(route('tasks.reopen', $done));
        $this->assertNull($done->fresh()->archived_at);
    }

    public function test_finished_tasks_go_to_the_archive_after_the_days_chosen_in_settings(): void
    {
        $old = $this->task(['title' => 'Old', 'status' => Task::DONE, 'completed_at' => $this->local('2026-11-03 10:00')]);
        $recent = $this->task(['title' => 'Recent', 'status' => Task::DONE, 'completed_at' => $this->local('2026-11-09 10:00')]);
        $gone = $this->task(['title' => 'Dropped', 'status' => Task::CANCELLED, 'cancelled_at' => $this->local('2026-11-02 10:00')]);

        // Never (the default): nothing moves.
        $this->get(route('tasks.index'));
        $this->assertNull($old->fresh()->archived_at);

        $this->patch(route('task-settings.update'), ['auto_archive_days' => 5])->assertSessionHasErrors('auto_archive_days');
        $this->patch(route('task-settings.update'), ['auto_archive_days' => 7])->assertSessionHasNoErrors();
        $this->get(route('tasks.index'));
        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertNotNull($gone->fresh()->archived_at);
        $this->assertNull($recent->fresh()->archived_at);

        // Taken out by hand: it stays out until it is finished again.
        $this->post(route('tasks.unarchive', $old));
        $this->get(route('tasks.archive'));
        $this->assertNull($old->fresh()->archived_at);

        $this->get(route('task-settings.edit'))->assertInertia(fn ($page) => $page->component('settings/tasks')->where('autoArchiveDays', 7));
    }

    public function test_a_follow_up_shows_when_its_day_comes_without_changing_the_order(): void
    {
        $waiting = $this->task(['title' => 'Ask Dr. Lim', 'status' => Task::TODO]);
        $ranked = $this->task(['title' => 'Chapter 4', 'importance' => 5]);
        $this->patch(route('tasks.update', $waiting), ['status' => 'waiting', 'waiting_for' => 'Dr. Lim']);
        $this->assertSame('2026-11-11 02:00', $waiting->fresh()->waiting_since->format('Y-m-d H:i'));

        $this->patch(route('tasks.update', $waiting), ['follow_up_on' => '2026-11-13'])->assertSessionHas('toast', 'Follow-up changed');
        $this->patch(route('tasks.update', $ranked), ['follow_up_on' => '2026-11-11']);
        $this->get(route('tasks.waiting'))->assertInertia(fn ($page) => $page->count('followUps', 1)
            ->where('followUps.0.id', $ranked->id)->where('followUps.0.rank', 1)->where('followUps.0.why', 'Follow up today'));

        // Two days on, the waiting one is due too, and says how long it has waited.
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', self::TZ));
        config(['velocity.fixed_now' => '2026-11-14 10:00']);
        $this->get(route('tasks.waiting'))->assertInertia(fn ($page) => $page->count('followUps', 2)
            ->where('followUps.1.why', 'Follow up · 1 day late · waiting for Dr. Lim, 3 days')
            ->where('notReady.0.why', 'Waiting for Dr. Lim · 3 days'));
        $this->get(route('home'))->assertInertia(fn ($page) => $page->count('attention.followUps', 2));
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->where('tasks.0.id', $ranked->id)->count('tasks', 1));
        $this->get(route('planner.index', ['view' => 'week', 'date' => '2026-11-09']))
            ->assertInertia(fn ($page) => $page->count('followUps', 2)->where('followUps.0.date', '2026-11-11'));

        // A finished task's follow-up stops showing; back to To do forgets "waiting since".
        $this->post(route('tasks.complete', $ranked));
        $this->patch(route('tasks.update', $waiting), ['status' => 'todo']);
        $this->assertNull($waiting->fresh()->waiting_since);
        $this->get(route('tasks.waiting'))->assertInertia(fn ($page) => $page->count('followUps', 1)->where('followUps.0.id', $waiting->id));
    }

    public function test_bulk_cancel_and_its_undo(): void
    {
        [$a, $b] = [$this->task(['title' => 'A']), $this->task(['title' => 'B'])];
        $this->post(route('tasks.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'cancel'])->assertSessionHas('toast', 'Cancelled 2 tasks');
        $this->assertSame([Task::CANCELLED, Task::CANCELLED], [$a->fresh()->status, $b->fresh()->status]);
        $this->post(route('tasks.bulk-undo'), ['ids' => [$a->id, $b->id], 'action' => 'cancel']);
        $this->assertSame([Task::TODO, Task::TODO], [$a->fresh()->status, $b->fresh()->status]);
    }
}
