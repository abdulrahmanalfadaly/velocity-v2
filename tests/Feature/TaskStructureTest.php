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
}
