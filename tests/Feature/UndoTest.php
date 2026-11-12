<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every action that changes something can be undone from its message. */
class UndoTest extends TestCase
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

    private function task(array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    /** Runs the Undo the last response offered, the way the toast's button does. */
    private function undo(): void
    {
        $undo = session('undo');
        $this->assertNotNull($undo, 'the response offered no Undo');
        $routes = [
            'reopen' => ['post', 'tasks.reopen'], 'complete' => ['post', 'tasks.complete'], 'restore' => ['post', 'tasks.restore'],
            'delete-task' => ['delete', 'tasks.destroy'], 'revert-task' => ['post', 'tasks.revert'], 'unpromote' => ['post', 'tasks.unpromote'],
            'restore-step' => ['post', 'steps.restore'], 'restore-milestone' => ['post', 'milestones.restore'],
            'restore-template' => ['post', 'templates.restore'], 'delete-template' => ['delete', 'templates.destroy'], 'patch-template' => ['patch', 'templates.update'],
            'archive-project' => ['post', 'projects.archive'], 'unarchive-project' => ['post', 'projects.unarchive'],
            'delete-project' => ['delete', 'projects.destroy'], 'patch-project' => ['patch', 'projects.update'],
            'archive-goal' => ['post', 'goals.archive'], 'unarchive-goal' => ['post', 'goals.unarchive'], 'delete-goal' => ['delete', 'goals.destroy'], 'patch-goal' => ['patch', 'goals.update'],
            'habit-count' => ['post', 'habits.check'], 'put-weights' => ['put', 'weights.update'],
        ];
        [$method, $route] = $routes[$undo['action']];
        $this->{$method}(route($route, $undo['id']), $undo['data'] ?? [])->assertSessionHasNoErrors();
    }

    public function test_changes_in_the_task_panel_can_be_undone(): void
    {
        $task = $this->task(['status' => 'doing']);
        $other = $this->task(['title' => 'Other']);

        $this->patch(route('tasks.update', $task), ['importance' => 5])->assertSessionHas('toast', 'Importance changed');
        $this->assertSame(5, $task->fresh()->importance);
        $this->undo();
        $this->assertSame(3, $task->fresh()->importance);

        $this->patch(route('tasks.update', $task), ['status' => 'waiting', 'waiting_for' => 'Bob']);
        $this->undo();
        $this->assertSame(['doing', null], [$task->fresh()->status, $task->fresh()->waiting_for]);

        $this->patch(route('tasks.update', $task), ['due_at' => '2026-11-20T09:00']);
        $this->undo();
        $this->assertNull($task->fresh()->due_at);

        $this->patch(route('tasks.update', $task), ['depends_on' => [$other->id]])->assertSessionHas('toast', 'Starts after changed');
        $this->assertSame(1, $task->dependencies()->count());
        $this->undo();
        $this->assertSame(0, $task->dependencies()->count());

        // An undo belongs to its own task: the event of one task can't be replayed on another.
        $this->patch(route('tasks.update', $task), ['title' => 'Renamed']);
        $event = session('undo')['data']['event'];
        $this->post(route('tasks.revert', $other), ['event' => $event])->assertNotFound();
        $this->assertSame('Renamed', $task->fresh()->title);
    }

    public function test_done_not_done_add_delete_and_restore_all_undo_each_other(): void
    {
        $task = $this->task(['status' => 'doing']);
        $this->post(route('tasks.complete', $task));
        $this->undo();
        $this->assertSame('doing', $task->fresh()->status);

        $this->post(route('tasks.reopen', $task)); // nothing to reopen: no undo offered
        $this->post(route('tasks.complete', $task));
        $this->post(route('tasks.reopen', $task))->assertSessionHas('toast', 'Not done: “Essay”');
        $this->undo();
        $this->assertSame('done', $task->fresh()->status);

        $this->post(route('tasks.store'), ['text' => 'Quick one'])->assertSessionHas('undo');
        $this->undo();
        $this->assertSoftDeleted(Task::withTrashed()->firstWhere('title', 'Quick one'));

        $this->delete(route('tasks.destroy', $task));
        $this->undo();
        $this->assertNull($task->fresh()->deleted_at);
        $this->post(route('tasks.restore', $task->id))->assertSessionHas('undo'); // restore again → undo = delete
        $this->undo();
        $this->assertSoftDeleted($task);
    }

    public function test_removing_a_step_or_making_it_a_task_can_be_undone(): void
    {
        $task = $this->task();
        $step = $task->steps()->create(['title' => 'Outline', 'position' => 0]);

        $this->delete(route('steps.destroy', $step))->assertSessionHas('toast', 'Removed step “Outline”');
        $this->assertSoftDeleted($step);
        $this->undo();
        $this->assertNull($step->fresh()->deleted_at);

        $this->post(route('steps.promote', $step))->assertSessionHas('undo');
        $new = Task::firstWhere('title', 'Outline');
        $this->assertNotNull($new);
        $this->assertSoftDeleted($step);
        $this->undo();
        $this->assertNull(Task::withTrashed()->find($new->id));
        $this->assertSame(['Outline'], $task->fresh()->steps->pluck('title')->all());
        // Someone else's step can't be brought back by guessing its id.
        $theirs = User::factory()->create()->tasks()->create(['title' => 'T', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1])->steps()->create(['title' => 's', 'position' => 0]);
        $theirs->delete();
        $this->post(route('steps.restore', $theirs->id))->assertNotFound();
        $this->assertSoftDeleted(TaskStep::class, ['id' => $theirs->id]);
    }

    public function test_milestones_templates_projects_and_goals_can_be_undone(): void
    {
        $project = $this->user->projects()->create(['name' => 'Thesis']);
        $m = $project->milestones()->create(['title' => 'Draft', 'position' => 0]);
        $this->delete(route('milestones.destroy', $m));
        $this->undo();
        $this->assertNull(Milestone::find($m->id)->deleted_at);

        $this->post(route('templates.store'), ['name' => 'Lab', 'title' => 'Lab report', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2])->assertSessionHasNoErrors();
        $t = TaskTemplate::firstOrFail();
        $this->patch(route('templates.update', $t), ['name' => 'Lab v2']);
        $this->undo();
        $this->assertSame('Lab', $t->fresh()->name);
        $this->delete(route('templates.destroy', $t));
        $this->undo();
        $this->assertNull($t->fresh()->deleted_at);
        $this->post(route('templates.use', $t))->assertSessionHas('undo');
        $this->undo();
        $this->assertSame(0, Task::where('title', 'Lab report')->count());

        $this->patch(route('projects.update', $project), ['name' => 'Final thesis', 'due_date' => '2026-12-01']);
        $this->undo();
        $this->assertSame(['Thesis', null], [$project->fresh()->name, $project->fresh()->due_date]);
        $this->post(route('projects.unarchive', $project)); // not archived: still offers "archive" as the undo
        $this->undo();
        $this->assertNotNull($project->fresh()->archived_at);

        $this->post(route('projects.store'), ['name' => 'Oops']);
        $this->undo();
        $this->assertNull($this->user->projects()->firstWhere('name', 'Oops'));
        $this->task(['project_id' => $project->id]);
        $this->delete(route('projects.destroy', $project))->assertSessionHas('toast', '“Thesis” already has things in it – archive it instead');
        $this->assertNotNull($project->fresh());

        $this->post(route('goals.store'), ['title' => 'Learn French', 'color' => 2]);
        $goal = $this->user->goals()->firstOrFail();
        $this->patch(route('goals.update', $goal), ['color' => 3]);
        $this->undo();
        $this->assertSame(2, $goal->fresh()->color);
        $this->undo(); // the response before: goal patch again… just check destroy on an empty goal via a fresh create
        $this->post(route('goals.store'), ['title' => 'Oops', 'color' => 1]);
        $this->undo();
        $this->assertNull($this->user->goals()->firstWhere('title', 'Oops'));
    }

    public function test_habit_taps_and_weights_reset_can_be_undone(): void
    {
        $water = $this->user->habits()->create(['name' => 'Water', 'target' => 8]);
        $count = fn () => (int) ($water->entries()->whereDate('day', '2026-11-11')->first()?->count ?? 0);
        $this->post(route('habits.check', $water));
        $this->post(route('habits.check', $water))->assertSessionHas('toast', 'Water: 2 of 8');
        $this->undo();
        $this->assertSame(1, $count());
        $this->undo(); // undoing the undo puts it back to 2
        $this->assertSame(2, $count());

        $this->put(route('weights.update'), ['weights' => ['I' => 50, 'U' => 20, 'D' => 20, 'P' => 10], 'horizonDays' => 10]);
        $this->delete(route('weights.reset'));
        $this->assertSame(42, $this->user->weights()->weights()->toPercentages()['I']);
        $this->undo();
        $profile = $this->user->fresh()->weights();
        $this->assertSame([50, 10.0], [$profile->weights()->toPercentages()['I'], (float) $profile->horizon_days]);
    }
}
