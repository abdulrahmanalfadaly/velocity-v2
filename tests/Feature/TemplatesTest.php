<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Templates: save a task as one, make tasks from one, edit and delete. */
class TemplatesTest extends TestCase
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

    public function test_a_task_can_be_saved_as_a_template_and_used_again(): void
    {
        $project = $this->user->projects()->create(['name' => 'Labs']);
        $task = $this->user->tasks()->create(['title' => 'Lab report', 'notes' => 'Use the rubric', 'importance' => 4, 'urgency' => 2, 'est_hours' => 3, 'project_id' => $project->id, 'repeat_unit' => 'week']);
        $task->steps()->create(['title' => 'Method', 'position' => 0]);
        $task->steps()->create(['title' => 'Results', 'position' => 1, 'done_at' => now()]);

        $this->post(route('templates.store'), ['task_id' => $task->id])->assertSessionHas('toast', 'Saved “Lab report” as a template');
        $template = TaskTemplate::firstOrFail();
        $this->assertSame(['Lab report', ['Method', 'Results'], 'week', $project->id], [$template->name, $template->steps, $template->repeat_unit, $template->project_id]);

        $this->post(route('templates.use', $template), ['due_at' => '2026-11-20T09:00'])->assertRedirect();
        $new = Task::where('title', 'Lab report')->where('id', '!=', $task->id)->firstOrFail();
        $this->assertSame(['Use the rubric', 4, 2, 3.0, $project->id, 'week'], [$new->notes, $new->importance, $new->urgency, $new->est_hours, $new->project_id, $new->repeat_unit]);
        $this->assertSame('2026-11-20 09:00', $new->due_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        // Steps start again, none done.
        $this->assertSame([['Method', null], ['Results', null]], $new->steps->map(fn ($s) => [$s->title, $s->done_at])->all());
        $this->assertSame(1, $template->fresh()->used);
        $this->get(route('templates.index'))->assertInertia(fn ($page) => $page->component('tasks/templates')->where('templates.0.used', 1));
    }

    public function test_templates_can_be_made_by_hand_edited_and_deleted(): void
    {
        $this->post(route('templates.store'), [
            'name' => 'Weekly review', 'title' => 'Review the week', 'importance' => 3, 'urgency' => 3, 'est_hours' => 0.5,
            'steps' => ['Look at History', '', '  Plan next week  '], 'repeat_unit' => 'week', 'repeat_every' => 1,
        ])->assertSessionHasNoErrors();
        $t = TaskTemplate::firstOrFail();
        $this->assertSame(['Look at History', 'Plan next week'], $t->steps);

        $this->patch(route('templates.update', $t), ['name' => 'Friday review', 'steps' => []]);
        $this->assertSame(['Friday review', []], [$t->fresh()->name, $t->fresh()->steps]);
        $this->post(route('templates.store'), ['name' => 'Bad', 'title' => 'x', 'importance' => 9, 'urgency' => 3, 'est_hours' => 1])->assertSessionHasErrors('importance');

        $this->delete(route('templates.destroy', $t));
        $this->assertSame(0, TaskTemplate::count());
    }

    public function test_someone_elses_template_or_task_is_out_of_reach(): void
    {
        $other = User::factory()->create();
        $theirTask = $other->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $theirs = $other->templates()->create(TaskTemplate::fromTask($theirTask->load('steps')));

        $this->post(route('templates.store'), ['task_id' => $theirTask->id])->assertNotFound();
        $this->post(route('templates.use', $theirs))->assertNotFound();
        $this->patch(route('templates.update', $theirs), ['name' => 'Mine'])->assertNotFound();
        $this->get(route('templates.index'))->assertInertia(fn ($page) => $page->where('templates', []));
    }
}
