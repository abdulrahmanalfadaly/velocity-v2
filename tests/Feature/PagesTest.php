<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStep;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Every screen and action works for the demo student, and nobody can touch someone else's data. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-09 09:00', 'velocity.study_log' => true]);
        $this->seed(DemoSeeder::class);
        $this->user = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_every_screen_renders_with_the_demo_data(): void
    {
        $this->actingAs($this->user);
        $fyp = Project::where('name', 'Final Year Project')->first();

        $this->get('/home')->assertInertia(fn (Assert $p) => $p->component('home')->where('top.0.title', 'Write Chapter 4 – Implementation')->where('top.0.score', 85.1)->has('habits', 5));
        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->component('tasks/index')->has('tasks', 14)->has('notReady', 3)->where('selected', null));
        $this->get("/projects/{$fyp->id}")->assertInertia(fn (Assert $p) => $p->component('projects/show')->where('project.name', 'Final Year Project')->has('milestones', 5));
        $this->get('/goals')->assertInertia(fn (Assert $p) => $p->component('goals/index')->has('goals', 3)->has('inbox', 2));
        $this->get('/weights')->assertInertia(fn (Assert $p) => $p->component('weights/edit')->where('weights', ['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12])->missing('ahp'));
        $this->get('/history')->assertInertia(fn (Assert $p) => $p->component('history/index')->has('weeks', 12));
        $this->getJson('/search?q=chap')->assertOk()->assertJsonPath('groups.0.label', 'Tasks');
    }

    public function test_inbox_and_not_ready_have_their_own_pages(): void
    {
        $this->actingAs($this->user);

        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->where('view', 'upnext')->where('nav.counts.inbox', 2)->where('nav.counts.waiting', 3));
        $this->get('/tasks/inbox')->assertInertia(fn (Assert $p) => $p->component('tasks/index')->where('view', 'inbox')
            ->has('tasks', 14)
            ->where('tasks', fn ($tasks) => collect($tasks)->whereNull('project')->pluck('rank')->all() === [12, 14]));
        $this->get('/tasks/waiting')->assertInertia(fn (Assert $p) => $p->component('tasks/index')->where('view', 'waiting')
            ->has('notReady', 3)
            ->where('notReady', fn ($rows) => collect($rows)->pluck('kind')->sort()->values()->all() === ['blocked', 'later', 'waiting']));

        // "Ready now" puts a waiting task back into the ranking.
        $waiting = Task::where('title', 'Revise Chapter 3 after feedback')->first();
        $this->patch("/tasks/{$waiting->id}", ['status' => Task::TODO])->assertRedirect();
        $this->get('/tasks/waiting')->assertInertia(fn (Assert $p) => $p->has('notReady', 2)->where('nav.counts.upNext', 15));
    }

    public function test_the_task_panel_explains_the_top_task(): void
    {
        $task = Task::where('title', 'Write Chapter 4 – Implementation')->first();
        $this->actingAs($this->user)->get("/tasks?task={$task->id}")->assertInertia(fn (Assert $p) => $p
            ->where('selected.rank', 1)
            ->where('selected.below.title', 'Test plan for Assignment 2')
            ->where('selected.contrast', fn ($c) => str_starts_with($c, 'Above Test plan for Assignment 2 mainly because it is more important')));
    }

    public function test_quick_add_understands_dates_hours_and_projects(): void
    {
        $this->actingAs($this->user)->post('/tasks', ['text' => 'Ethics essay outline fri 3h #fyp', 'importance' => 4])->assertRedirect();
        $task = Task::where('title', 'Ethics essay outline')->firstOrFail();

        $this->assertSame(3.0, $task->est_hours);
        $this->assertSame('Final Year Project', $task->project->name);
        $this->assertSame('2026-11-13 23:59', $task->due_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
    }

    public function test_quick_add_preview_shows_where_a_task_will_land(): void
    {
        $this->actingAs($this->user)->postJson('/tasks/preview', ['text' => 'Ethics essay outline fri 3h', 'importance' => 4, 'urgency' => 3])
            ->assertOk()->assertJsonPath('preview.rank', 6)->assertJsonPath('preview.score', 58.8);
    }

    public function test_completing_and_undoing_a_task(): void
    {
        $task = Task::where('title', 'Test plan for Assignment 2')->first();
        $this->actingAs($this->user)->post("/tasks/{$task->id}/complete")->assertSessionHas('undo', ['action' => 'reopen', 'id' => $task->id]);
        $this->assertSame(Task::DONE, $task->fresh()->status);
        $this->assertSame(78.7, $task->fresh()->score_at_completion);

        $this->post("/tasks/{$task->id}/reopen");
        $this->assertSame(Task::TODO, $task->fresh()->status);
    }

    public function test_a_step_can_become_its_own_ranked_task(): void
    {
        $step = TaskStep::where('title', 'Weights section')->first();
        $this->actingAs($this->user)->post("/steps/{$step->id}/promote")->assertRedirect();
        $task = Task::where('title', 'Weights section')->firstOrFail();

        $this->assertSame('Write Chapter 4 – Implementation', $task->parent->title);
        $this->assertNull(TaskStep::find($step->id));
        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->has('tasks', 15));
    }

    public function test_changing_weights_reranks(): void
    {
        $this->actingAs($this->user)->put('/weights', ['weights' => ['I' => 20, 'U' => 32, 'D' => 32, 'P' => 16]])->assertRedirect();
        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->where('tasks.0.title', 'Test plan for Assignment 2'));

        $this->delete('/weights');
        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->where('tasks.0.title', 'Write Chapter 4 – Implementation'));
    }

    public function test_weights_must_add_up_to_100(): void
    {
        $this->actingAs($this->user)->put('/weights', ['weights' => ['I' => 50, 'U' => 30, 'D' => 30, 'P' => 10]])->assertStatus(422);
    }

    public function test_nobody_can_see_or_change_someone_elses_tasks(): void
    {
        $other = User::factory()->create();
        $task = Task::where('user_id', $this->user->id)->first();
        $project = Project::where('user_id', $this->user->id)->first();

        $this->actingAs($other)->patch("/tasks/{$task->id}", ['title' => 'Hacked'])->assertNotFound();
        $this->post("/tasks/{$task->id}/complete")->assertNotFound();
        $this->delete("/tasks/{$task->id}")->assertNotFound();
        $this->get("/projects/{$project->id}")->assertNotFound();
        $this->get("/tasks?task={$task->id}")->assertInertia(fn (Assert $p) => $p->where('selected', null)->has('tasks', 0));
        $this->assertNotSame('Hacked', $task->fresh()->title);
    }
}
