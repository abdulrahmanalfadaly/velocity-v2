<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** How tasks hang together: a goal straight on a task, tasks waiting on others, subtasks. */
class TaskStructureTest extends TestCase
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

    public function test_a_task_with_no_project_can_have_its_own_goal_and_a_project_takes_it_over(): void
    {
        $japan = $this->user->goals()->create(['title' => 'Move to Japan', 'color' => 2]);
        $fyp = $this->user->goals()->create(['title' => 'Finish the FYP', 'color' => 1]);
        $project = $this->user->projects()->create(['name' => 'Chapter 3', 'goal_id' => $fyp->id, 'due_date' => '2026-11-20']);
        $passport = $this->task(['title' => 'Renew passport']);

        $this->patch(route('tasks.update', $passport), ['goal_id' => $japan->id])->assertSessionHas('toast', 'Goal changed');
        $this->get(route('tasks.index', ['task' => $passport->id]))
            ->assertInertia(fn ($page) => $page->where('selected.goal.title', 'Move to Japan'));
        // On the Goals map it hangs under the goal, not in the map's inbox.
        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page
            ->where('goals.0.tasks.0.title', 'Renew passport')
            ->count('inbox', 0));

        // A task in a project can't have a goal of its own; moving it into one drops it.
        $this->patch(route('tasks.update', $passport), ['project_id' => $project->id]);
        $this->assertNull($passport->fresh()->goal_id);
        $this->patch(route('tasks.update', $passport), ['goal_id' => $japan->id])->assertSessionHasErrors('goal_id');
        $this->get(route('tasks.index', ['task' => $passport->id]))->assertInertia(fn ($page) => $page
            ->where('selected.goal.title', 'Finish the FYP')
            ->where('selected.project.dueDate', '2026-11-20'));
    }

    public function test_no_task_holds_another_back(): void
    {
        $data = $this->task(['title' => 'Get the data']);
        $chart = $this->task(['title' => 'Make the chart']);
        // A "starts after" link made before it was taken out changes nothing, and none can be made now.
        $chart->dependencies()->attach($data->id);
        $this->patch(route('tasks.update', $data), ['depends_on' => [$chart->id]]);
        $this->assertSame(0, $data->dependencies()->count());

        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->has('tasks', 2));
        $this->get(route('tasks.index', ['task' => $chart->id]))->assertInertia(fn ($page) => $page->missing('selected.dependsOn')->missing('selected.blocks'));
        $this->post(route('tasks.complete', $chart))->assertSessionHas('toast', 'Done: “Make the chart”');
        $this->assertSame(Task::DONE, $chart->fresh()->status);
    }

    public function test_the_activity_list_says_what_happened_in_plain_words(): void
    {
        $project = $this->user->projects()->create(['name' => 'Chapter 3']);
        $this->post(route('tasks.store'), ['text' => 'Essay wed']);
        $essay = Task::where('title', 'Essay')->sole();
        $this->patch(route('tasks.update', $essay), ['due_at' => '2026-11-13 17:00']);
        $this->patch(route('tasks.update', $essay), ['project_id' => $project->id]);
        $this->patch(route('tasks.update', $essay), ['importance' => 5]);
        $this->post(route('tasks.complete', $essay));

        $this->get(route('tasks.index', ['task' => $essay->id]))->assertInertia(fn ($page) => $page
            ->where('selected.activity.0.text', 'Done')
            ->where('selected.activity.1.text', 'Importance very high')
            ->where('selected.activity.2.text', 'Moved to “Chapter 3”')
            ->where('selected.activity.3.text', 'Due moved Wed 11 Nov → Fri 13 Nov')
            ->where('selected.activity.4.text', 'Added'));
    }

    public function test_subtasks_are_tasks_of_their_own_and_the_parent_asks_about_them(): void
    {
        $report = $this->task(['title' => 'Write the report', 'importance' => 4, 'due_at' => '2026-11-20 09:00']);
        $this->post(route('tasks.subtask', $report), ['title' => 'Intro'])->assertSessionHas('toast', 'Added subtask “Intro”');
        $this->post(route('tasks.subtask', $report), ['title' => 'Method']);
        $intro = Task::where('title', 'Intro')->sole();
        $this->assertSame([$report->id, 4, '2026-11-20 09:00'], [$intro->parent_id, $intro->importance, $intro->due_at->format('Y-m-d H:i')]);
        // Ranked like any task.
        $this->get(route('tasks.index', ['task' => $report->id]))->assertInertia(fn ($page) => $page
            ->count('tasks', 3)->where('selected.children.0.title', 'Intro')->where('selected.children.0.status', 'todo'));

        // Open subtasks: the parent asks first.
        $this->post(route('tasks.complete', $report))->assertSessionHas('toast', '“Write the report” has 2 open subtasks. Open it to choose.');
        $this->post(route('tasks.complete', $intro));
        $this->post(route('tasks.complete', $report), ['children' => 'complete'])->assertSessionHas('toast', 'Done: “Write the report” and 1 subtask');
        $this->assertSame([Task::DONE, Task::DONE], [$report->fresh()->status, Task::where('title', 'Method')->sole()->status]);
        $this->post(route('tasks.bulk-undo'), session('undo')['data']);
        $this->assertSame([Task::TODO, Task::TODO, Task::DONE], [$report->fresh()->status, Task::where('title', 'Method')->sole()->status, $intro->fresh()->status]);
        $this->post(route('tasks.complete', $report), ['children' => 'leave'])->assertSessionHas('toast', 'Done: “Write the report”');
        $this->assertSame(Task::TODO, Task::where('title', 'Method')->sole()->status);
        $this->post(route('tasks.reopen', $report));

        // Cancelling the parent: its open subtasks go with it (same reason) and Undo brings them all back; or they stay open.
        $this->post(route('tasks.cancel', $report), ['reason' => 'Dropped', 'children' => 'cancel'])->assertSessionHas('toast', 'Cancelled “Write the report” and 1 subtask');
        $method = Task::where('title', 'Method')->sole();
        $this->assertSame([Task::CANCELLED, Task::CANCELLED, 'Dropped', Task::DONE], [$report->fresh()->status, $method->status, $method->cancel_reason, $intro->fresh()->status]);
        $this->post(route('tasks.bulk-undo'), session('undo')['data']);
        $this->assertSame([Task::TODO, Task::TODO], [$report->fresh()->status, $method->fresh()->status]);
        $this->post(route('tasks.cancel', $report), ['children' => 'leave'])->assertSessionHas('toast', 'Cancelled “Write the report”')->assertSessionHas('undo.action', 'reopen');
        $this->assertSame(Task::TODO, $method->fresh()->status);
        $this->post(route('tasks.reopen', $report), session('undo')['data']);

        // Deleting the parent: its subtasks too, and Undo brings them all back; or they stay on their own.
        $this->delete(route('tasks.destroy', $report), ['children' => 'delete'])->assertSessionHas('toast', 'Deleted “Write the report” and its 2 subtasks');
        $this->assertSame(0, $this->user->tasks()->count());
        $this->post(route('tasks.restore', $report->id));
        $this->assertSame(3, $this->user->tasks()->count());
        $this->delete(route('tasks.destroy', $report))->assertSessionHas('toast', 'Deleted “Write the report”');
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('tasks', 1)->where('tasks.0.title', 'Method')->where('tasks.0.parent', null));
    }
}
