<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 13: a project can serve several goals; the first is its main one. */
class ProjectGoalsPhase13Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Kuala_Lumpur'));
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    public function test_a_project_in_two_goals_shows_under_both_and_counts_once(): void
    {
        $first = $this->user->goals()->create(['title' => 'Graduate with a First', 'color' => 1, 'position' => 1]);
        $job = $this->user->goals()->create(['title' => 'Land an internship', 'color' => 2, 'position' => 2, 'progress_mode' => 'milestones']);
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'goal_id' => $first->id]);
        $fyp->milestones()->create(['title' => 'Demo', 'position' => 1, 'done_at' => now()]);
        $this->user->tasks()->create(['project_id' => $fyp->id, 'title' => 'Write chapter 3', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);

        $this->patch(route('projects.update', $fyp), ['goal_ids' => [$first->id, $job->id]])->assertSessionHasNoErrors()
            ->assertSessionHas('undo.data', ['goal_ids' => [$first->id]]);
        $fyp->refresh();
        $this->assertSame($first->id, $fyp->goal_id);
        $this->assertSame([$first->id, $job->id], $fyp->goalIds());

        $this->get(route('projects.show', $fyp))->assertInertia(fn (Assert $p) => $p
            ->where('project.goal.id', $first->id)
            ->where('project.goals', fn ($g) => collect($g)->pluck('title')->all() === ['Graduate with a First', 'Land an internship']));
        $this->get(route('projects.index'))->assertInertia(fn (Assert $p) => $p->where('projects.0.goals.1.title', 'Land an internship'));

        // On the map: under both goals, the second time marked "also"; not under Other projects.
        $this->get(route('goals.index'))->assertInertia(fn (Assert $p) => $p
            ->has('goals', 2)
            ->where('goals.0.projects.0.id', $fyp->id)->where('goals.0.projects.0.also', false)
            ->where('goals.1.projects.0.id', $fyp->id)->where('goals.1.projects.0.also', true)
            ->where('goals.1.projects.0.tasks.0.title', 'Write chapter 3')
            // Its milestones count for the goal it serves besides.
            ->where('goals.1.progressPct', 100));

        // Swapping the order makes the other one the main goal; taking one off leaves the other.
        $this->patch(route('projects.update', $fyp), ['goal_ids' => [$job->id, $first->id]]);
        $this->assertSame($job->id, $fyp->fresh()->goal_id);
        $this->patch(route('projects.update', $fyp), ['goal_ids' => [$first->id]]);
        $this->assertSame([$first->id], $fyp->fresh()->goalIds());
        $this->patch(route('projects.update', $fyp), ['goal_ids' => []]);
        $this->assertNull($fyp->fresh()->goal_id);
        // An old client sending goal_id only: that goal is never an extra one too.
        $fyp->extraGoals()->attach($job->id);
        $this->patch(route('projects.update', $fyp), ['goal_id' => $job->id]);
        $this->assertSame([$job->id], $fyp->fresh()->goalIds());
        // Another user's goal can't be added.
        $other = User::factory()->create()->goals()->create(['title' => 'Not mine', 'color' => 1]);
        $this->patch(route('projects.update', $fyp), ['goal_ids' => [$other->id]])->assertSessionHasErrors('goal_ids.0');
    }

    public function test_deleting_the_main_goal_moves_up_the_next_and_undo_puts_it_back(): void
    {
        $first = $this->user->goals()->create(['title' => 'Graduate with a First', 'color' => 1, 'position' => 1]);
        $job = $this->user->goals()->create(['title' => 'Land an internship', 'color' => 2, 'position' => 2]);
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'goal_id' => $first->id]);
        $fyp->extraGoals()->attach($job->id);
        $side = $this->user->projects()->create(['name' => 'Portfolio', 'goal_id' => $job->id]);
        $side->extraGoals()->attach($first->id);

        $this->delete(route('goals.destroy', $first));
        $undo = session('undo');
        $this->assertSame([$job->id], $fyp->fresh()->goalIds());
        // Where it was an extra goal, it's just hidden while deleted.
        $this->assertSame([$job->id], Project::find($side->id)->goalIds());

        $this->post(route('goals.restore', $first->id), $undo['data'])->assertSessionHasNoErrors();
        $this->assertSame([$first->id, $job->id], $fyp->fresh()->goalIds());
        $this->assertSame([$job->id, $first->id], Project::find($side->id)->goalIds());
    }

    public function test_the_api_reads_and_sets_goal_ids(): void
    {
        $key = $this->user->createToken('Mark', ['*'])->plainTextToken;
        $first = $this->user->goals()->create(['title' => 'Graduate with a First', 'color' => 1]);
        $job = $this->user->goals()->create(['title' => 'Land an internship', 'color' => 2]);
        $this->app['auth']->forgetGuards();

        $id = $this->json('POST', '/api/v1/projects', ['name' => 'FYP', 'goal_ids' => [$job->id, $first->id]], ['Authorization' => 'Bearer '.$key])
            ->assertCreated()->assertJsonPath('data.goal.id', $job->id)->assertJsonPath('data.goalIds', [$job->id, $first->id])->json('data.id');
        $this->json('GET', "/api/v1/projects/{$id}", [], ['Authorization' => 'Bearer '.$key])->assertJsonPath('data.goalIds', [$job->id, $first->id]);
    }
}
