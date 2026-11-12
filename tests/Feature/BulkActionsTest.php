<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One action on several tasks at once, and its undo. */
class BulkActionsTest extends TestCase
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

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function undo(): void
    {
        $undo = session('undo');
        $this->assertSame('bulk-undo', $undo['action']);
        $this->post(route('tasks.bulk-undo'), $undo['data'])->assertSessionHasNoErrors();
    }

    public function test_several_tasks_are_done_at_once_with_their_scores_and_undone_together(): void
    {
        $a = $this->task('A', ['status' => 'doing', 'importance' => 5]);
        $b = $this->task('B', ['repeat_unit' => 'day']);
        $c = $this->task('C');

        $this->post(route('tasks.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'complete'])->assertSessionHas('toast', '2 tasks done');
        $this->assertSame(['done', 'done', 'todo'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
        $this->assertNotNull($a->fresh()->score_at_completion);
        $this->assertGreaterThan($b->fresh()->score_at_completion, $a->fresh()->score_at_completion);
        $this->assertSame(1, Task::where('repeated_from_id', $b->id)->count()); // the repeating one made its next copy

        $this->undo();
        $this->assertSame(['doing', 'todo'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame(0, Task::where('repeated_from_id', $b->id)->count());
    }

    public function test_move_and_due_date_put_each_task_back_where_it_was(): void
    {
        $p1 = $this->user->projects()->create(['name' => 'One']);
        $p2 = $this->user->projects()->create(['name' => 'Two']);
        $a = $this->task('A', ['project_id' => $p1->id, 'due_at' => '2026-11-12 01:00:00']);
        $b = $this->task('B');

        $this->post(route('tasks.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'move', 'project_id' => $p2->id])->assertSessionHas('toast', 'Moved 2 tasks');
        $this->assertSame([$p2->id, $p2->id], [$a->fresh()->project_id, $b->fresh()->project_id]);
        $this->undo();
        $this->assertSame([$p1->id, null], [$a->fresh()->project_id, $b->fresh()->project_id]);

        $this->post(route('tasks.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'due', 'due_at' => '2026-11-20T09:00']);
        $this->assertSame('2026-11-20 09:00', $b->fresh()->due_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->undo();
        $this->assertSame(['2026-11-12 01:00:00', null], [$a->fresh()->due_at->format('Y-m-d H:i:s'), $b->fresh()->due_at]);

        // Moving to the Inbox (no project).
        $this->post(route('tasks.bulk'), ['ids' => [$a->id], 'action' => 'move', 'project_id' => null]);
        $this->assertNull($a->fresh()->project_id);
    }

    public function test_delete_and_restore_together_and_other_peoples_tasks_are_left_alone(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $done = $this->task('Done already', ['status' => 'done', 'completed_at' => now()]);
        $other = User::factory()->create();
        $theirs = $other->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $theirProject = $other->projects()->create(['name' => 'Theirs']);

        $this->post(route('tasks.bulk'), ['ids' => [$a->id, $b->id, $done->id, $theirs->id], 'action' => 'delete'])->assertSessionHas('toast', 'Deleted 2 tasks');
        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertNull($done->fresh()->deleted_at); // done tasks are not part of a bulk action
        $this->assertNull($theirs->fresh()->deleted_at);

        $this->undo();
        $this->assertNull($a->fresh()->deleted_at);
        $this->assertNull($b->fresh()->deleted_at);

        $this->post(route('tasks.bulk'), ['ids' => [$a->id], 'action' => 'move', 'project_id' => $theirProject->id])->assertSessionHasErrors('project_id');
        $this->post(route('tasks.bulk'), ['ids' => [], 'action' => 'complete'])->assertSessionHasErrors('ids');
        $this->post(route('tasks.bulk'), ['ids' => [$a->id], 'action' => 'explode'])->assertSessionHasErrors('action');
        // An undo can't be used to reach someone else's task.
        $this->post(route('tasks.bulk-undo'), ['action' => 'move', 'ids' => [$theirs->id], 'before' => [$theirs->id => ['project_id' => null]]]);
        $this->post(route('tasks.bulk'), ['ids' => [$theirs->id], 'action' => 'complete']);
        $this->assertSame('todo', $theirs->fresh()->status);
    }

    public function test_a_done_task_dropped_on_a_board_column_opens_in_that_column(): void
    {
        $task = $this->task('Card', ['status' => 'doing']);
        $this->post(route('tasks.complete', $task));
        $this->post(route('tasks.reopen', $task), ['status' => 'waiting']);
        $this->assertSame('waiting', $task->fresh()->status);
        $this->post(route('tasks.complete', $task));
        $this->post(route('tasks.reopen', $task), ['status' => 'done'])->assertSessionHasErrors('status');
        $this->post(route('tasks.reopen', $task));
        $this->assertSame('waiting', $task->fresh()->status); // without a status: back to what it was
    }
}
