<?php

namespace Tests\Feature;

use App\Domain\Prioritization\ScenarioSet;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** FYP mode: Velocity as the report describes it, and nothing else. */
class FypModeTest extends TestCase
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

    private function fyp(): User
    {
        $this->user->update(['fyp_mode' => true]);

        return $this->user->fresh();
    }

    public function test_fyp_mode_is_a_setting(): void
    {
        $this->actingAs($this->user)->get('/settings/mode')->assertInertia(fn (Assert $p) => $p->component('settings/mode')->where('fypMode', false));

        $this->patch('/settings/mode', ['fyp_mode' => true])->assertRedirect('/settings/mode');
        $this->assertTrue($this->user->fresh()->fyp_mode);
        $this->actingAs($this->user->fresh())->get('/settings/mode')->assertInertia(fn (Assert $p) => $p->where('fypMode', true)->where('auth.user.fypMode', true));

        $this->patch('/settings/mode', ['fyp_mode' => false]);
        $this->assertFalse($this->user->fresh()->fyp_mode);
    }

    public function test_fyp_mode_shows_only_the_reports_screens(): void
    {
        $this->actingAs($this->fyp());
        $project = Project::where('user_id', $this->user->id)->first();

        foreach (['/home', '/goals', "/projects/{$project->id}"] as $url) {
            $this->get($url)->assertRedirect('/tasks');
        }
        $this->getJson('/search?q=chap')->assertNotFound();

        $this->get('/tasks')->assertInertia(fn (Assert $p) => $p->component('fyp/tasks')
            ->has('tasks', 14)
            ->where('tasks.0.title', 'Write Chapter 4 – Implementation')
            ->where('tasks.0.score', 85.1)
            ->where('tasks.0.contrast', fn ($c) => str_starts_with($c, 'Above Test plan for Assignment 2 mainly because it is more important'))
            ->has('completed')
            ->where('nav', null));
        $this->get('/weights')->assertInertia(fn (Assert $p) => $p->component('fyp/weights')->where('ahp.cr', 0.004)->has('tasks', 14));
        $this->get('/history')->assertInertia(fn (Assert $p) => $p->component('fyp/history')->has('weeks', 12)->has('activity'));
    }

    public function test_personal_mode_keeps_its_own_screens(): void
    {
        $this->actingAs($this->user)->get('/tasks')->assertInertia(fn (Assert $p) => $p->component('tasks/index'));
        $this->get('/weights')->assertInertia(fn (Assert $p) => $p->component('weights/edit')->missing('ahp'));
        $this->get('/history')->assertInertia(fn (Assert $p) => $p->component('history/index')->where('activity', []));
        $this->get('/home')->assertOk();
    }

    public function test_only_fyp_mode_is_recorded_for_the_study(): void
    {
        $this->actingAs($this->user)->get('/tasks');
        $this->put('/weights', ['weights' => ['I' => 20, 'U' => 32, 'D' => 32, 'P' => 16]]);
        $this->assertDatabaseCount('study_log', 0);

        $this->actingAs($this->fyp())->get('/tasks');
        $this->put('/weights', ['weights' => ['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12]]);
        $this->assertDatabaseHas('study_log', ['user_id' => $this->user->id, 'kind' => 'ranking_view']);
        $this->assertDatabaseHas('study_log', ['user_id' => $this->user->id, 'kind' => 'weights_change']);
    }

    public function test_the_task_form_adds_a_task_with_every_attribute(): void
    {
        $this->actingAs($this->fyp())
            ->post('/tasks', ['title' => 'Ethics essay outline', 'notes' => 'Module CS301', 'importance' => 4, 'urgency' => 3, 'due_at' => '2026-11-13T23:59', 'est_hours' => 3])
            ->assertRedirect()
            ->assertSessionHas('toast', 'Added “Ethics essay outline” – ranked #6 of 15 with a score of 58.8.');

        $task = Task::where('title', 'Ethics essay outline')->firstOrFail();
        $this->assertSame('Module CS301', $task->notes);
        $this->assertSame('2026-11-13 23:59', $task->due_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->assertSame(3.0, $task->est_hours);
        $this->assertNull($task->project_id);
    }

    public function test_the_task_form_checks_its_fields(): void
    {
        $this->actingAs($this->fyp())->post('/tasks', ['title' => '', 'importance' => 6, 'urgency' => 3, 'est_hours' => null])
            ->assertSessionHasErrors(['title', 'importance', 'est_hours']);
    }

    public function test_the_form_preview_shows_the_score_and_place(): void
    {
        $this->actingAs($this->fyp())->postJson('/tasks/preview', ['importance' => 4, 'urgency' => 3, 'due_at' => '2026-11-13T23:59', 'est_hours' => 3])
            ->assertOk()
            ->assertJsonPath('preview.rank', 6)
            ->assertJsonPath('preview.total', 15)
            ->assertJsonPath('preview.score', 58.8)
            ->assertJsonPath('preview.params.weights.I', 42);
    }

    public function test_editing_with_the_form(): void
    {
        $task = Task::where('title', 'Write Chapter 4 – Implementation')->first();
        $this->actingAs($this->fyp());

        // Left out of the comparison, the task is placed among the other 13.
        $this->postJson('/tasks/preview', ['importance' => 1, 'urgency' => 1, 'due_at' => null, 'est_hours' => 1, 'except' => $task->id])
            ->assertJsonPath('preview.total', 14)
            ->assertJsonPath('preview.rank', 14);

        $this->patch("/tasks/{$task->id}", ['form' => true, 'title' => 'Write Chapter 4', 'notes' => null, 'importance' => 1, 'urgency' => 1, 'due_at' => null, 'est_hours' => 1])
            ->assertSessionHas('toast', fn ($t) => str_starts_with($t, 'Saved “Write Chapter 4” – ranked #14 of 14'));
        $this->assertNull($task->fresh()->due_at);
    }

    public function test_the_scenario_set_ranks_like_table_3_4(): void
    {
        $student = User::factory()->create(['fyp_mode' => true]);
        $this->actingAs($student)->post('/tasks/scenario')->assertRedirect('/tasks');

        $this->get('/tasks')->assertInertia(function (Assert $p) {
            $p->component('fyp/tasks')->has('tasks', 10);
            foreach (ScenarioSet::EXPECTED_ORDER as $i => $code) {
                $p->where("tasks.{$i}.title", fn ($t) => str_starts_with($t, "{$code} "))->where("tasks.{$i}.score", ScenarioSet::EXPECTED_SCORES[$code]);
            }
        });
    }

    public function test_the_ranking_can_be_exported_as_json(): void
    {
        $this->actingAs($this->fyp())->get('/ranking/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="velocity-ranking-2026-11-09-0900.json"')
            ->assertJsonPath('parameters.weights', ['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12])
            ->assertJsonPath('tasks.0.title', 'Write Chapter 4 – Implementation')
            ->assertJsonPath('tasks.0.score', 85.1)
            ->assertJsonCount(14, 'tasks');
    }

    public function test_new_accounts_can_start_in_fyp_mode(): void
    {
        config(['velocity.fyp_default' => true]);
        $this->assertTrue(User::factory()->create()->fyp_mode);

        config(['velocity.fyp_default' => false]);
        $this->assertFalse(User::factory()->create()->fyp_mode);
    }
}
