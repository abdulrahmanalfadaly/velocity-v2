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

    public function test_a_task_still_waiting_asks_before_it_is_done_and_the_tasks_after_it_hear_about_it(): void
    {
        $data = $this->task(['title' => 'Get the data']);
        $chart = $this->task(['title' => 'Make the chart']);
        $this->post(route('tasks.blocks', $data), ['add' => $chart->id])->assertSessionHas('toast', '“Make the chart” now starts after this one');
        $this->get(route('tasks.index', ['task' => $data->id]))->assertInertia(fn ($page) => $page->where('selected.blocks.0.title', 'Make the chart'));

        // A loop is refused.
        $this->post(route('tasks.blocks', $chart), ['add' => $data->id])->assertSessionHasErrors('depends_on');

        // "Make the chart" still waits: ticking it only says so, unless "anyway".
        $this->post(route('tasks.complete', $chart))->assertSessionHas('toast', '“Make the chart” is still waiting on “Get the data”. Open it to complete it anyway.');
        $this->assertSame(Task::TODO, $chart->fresh()->status);

        // Done: what waited on it can start. Opened again: it waits again.
        $this->post(route('tasks.complete', $data))->assertSessionHas('toast', 'Done: “Get the data” · “Make the chart” can start now');
        $this->post(route('tasks.reopen', $data))->assertSessionHas('toast', 'Not done: “Get the data” · “Make the chart” waits on it again')
            ->assertSessionHas('undo.data.anyway', true);
        $this->post(route('tasks.complete', $chart), ['anyway' => true])->assertSessionHas('toast', 'Done: “Make the chart”');

        // Taken off the Blocks list, with Undo.
        $this->post(route('tasks.blocks', $data), ['remove' => $chart->id]);
        $this->assertSame(0, $chart->dependencies()->count());
        $this->post(route('tasks.revert', $chart), session('undo')['data']);
        $this->assertSame([$data->id], $chart->dependencies()->pluck('tasks.id')->all());
    }
}
