<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Projects, goals, milestones, habits, the archive and the task panel's fields – plus the bugs found in the deep test. */
class WorkspaceTest extends TestCase
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

    public function test_a_new_user_can_make_a_project_with_a_new_area_and_see_it_in_the_menu(): void
    {
        $this->get('/tasks')->assertInertia(fn ($page) => $page->where('nav.areas', [])->where('nav.loose', [])->where('nav.goals', []));

        $this->post(route('projects.store'), ['name' => 'Thesis', 'new_area' => 'University', 'due_date' => '2026-12-01'])
            ->assertRedirect(route('projects.show', Project::firstWhere('name', 'Thesis')));
        $this->post(route('projects.store'), ['name' => 'Side quest']);

        $this->get('/tasks')->assertInertia(fn ($page) => $page
            ->where('nav.areas.0.name', 'University')
            ->where('nav.areas.0.projects.0.name', 'Thesis')
            ->where('nav.loose.0.name', 'Side quest'));
        $this->post(route('projects.store'), ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_project_can_be_edited_archived_and_restored(): void
    {
        $goal = $this->user->goals()->create(['title' => 'Graduate', 'color' => 2]);
        $project = $this->user->projects()->create(['name' => 'Thesis']);

        $this->patch(route('projects.update', $project), ['name' => 'Final thesis', 'goal_id' => $goal->id, 'description' => 'Chapters 1–6'])->assertSessionHasNoErrors();
        $this->assertSame(['Final thesis', $goal->id, 'Chapters 1–6'], [$project->fresh()->name, $project->fresh()->goal_id, $project->fresh()->description]);

        $this->post(route('projects.archive', $project))->assertSessionHas('undo', ['action' => 'unarchive-project', 'id' => $project->id]);
        $this->get('/tasks')->assertInertia(fn ($page) => $page->where('nav.loose', []));
        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page->component('tasks/archive')->where('projects.0.name', 'Final thesis'));
        $this->get(route('projects.show', $project))->assertInertia(fn ($page) => $page->where('project.archived', true));

        $this->post(route('projects.unarchive', $project));
        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_someone_elses_project_goal_or_habit_is_out_of_reach(): void
    {
        $other = User::factory()->create();
        $project = $other->projects()->create(['name' => 'Theirs']);
        $goal = $other->goals()->create(['title' => 'Theirs', 'color' => 1]);
        $habit = $other->habits()->create(['name' => 'Theirs']);

        $this->patch(route('projects.update', $project), ['name' => 'Mine'])->assertNotFound();
        $this->post(route('milestones.store', $project), ['title' => 'x'])->assertNotFound();
        $this->post(route('goals.archive', $goal))->assertNotFound();
        $this->post(route('habits.check', $habit))->assertNotFound();
        $this->post(route('projects.store'), ['name' => 'Mine', 'goal_id' => $goal->id])->assertSessionHasErrors('goal_id');
    }

    public function test_goals_can_be_made_edited_and_archived_and_projects_without_one_stay_on_the_map(): void
    {
        $this->post(route('goals.store'), ['title' => 'Get fit', 'color' => 3, 'icon' => 'heart'])->assertSessionHasNoErrors();
        $goal = Goal::firstWhere('title', 'Get fit');
        $this->patch(route('goals.update', $goal), ['why' => 'Energy for exams', 'target' => 'Jun 2027']);
        $this->assertSame(['Energy for exams', 'Jun 2027'], [$goal->fresh()->why, $goal->fresh()->target]);
        $this->post(route('goals.store'), ['title' => 'Bad', 'color' => 9])->assertSessionHasErrors('color');

        $withGoal = $this->user->projects()->create(['name' => 'Run 5K', 'goal_id' => $goal->id]);
        $noGoal = $this->user->projects()->create(['name' => 'Odd jobs']);
        $this->task(['project_id' => $noGoal->id, 'title' => 'Fix bike']);

        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page
            ->where('goals.0.title', 'Get fit')
            ->where('goals.1.id', 0)
            ->where('goals.1.projects.0.name', 'Odd jobs')
            ->where('goals.1.projects.0.tasks.0.title', 'Fix bike'));

        // Archiving the goal moves its project to "Other projects" instead of hiding it.
        $this->post(route('goals.archive', $goal));
        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page->where('goals.0.id', 0)->has('goals.0.projects', 2));
        $this->assertSame($goal->id, $withGoal->fresh()->goal_id);
    }

    public function test_milestones_can_be_added_ticked_and_removed(): void
    {
        $project = $this->user->projects()->create(['name' => 'Thesis']);
        $this->post(route('milestones.store', $project), ['title' => 'Draft', 'due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $m = $project->milestones()->first();
        $this->patch(route('milestones.update', $m), ['done' => true]);
        $this->assertNotNull($m->fresh()->done_at);
        $this->patch(route('milestones.update', $m), ['done' => false]);
        $this->assertNull($m->fresh()->done_at);
        $this->delete(route('milestones.destroy', $m));
        $this->assertSame(0, $project->milestones()->count());
    }

    public function test_a_habit_is_checked_in_by_tapping(): void
    {
        $once = $this->user->habits()->create(['name' => 'Read']);
        $water = $this->user->habits()->create(['name' => 'Water', 'target' => 3]);
        $count = fn (Habit $h) => (int) ($h->entries()->whereDate('day', '2026-11-11')->first()?->count ?? 0);

        $this->post(route('habits.check', $once));
        $this->assertSame(1, $count($once));
        $this->post(route('habits.check', $once));
        $this->assertSame(0, $count($once));

        foreach ([1, 2, 3, 0] as $expected) {
            $this->post(route('habits.check', $water));
            $this->assertSame($expected, $count($water));
        }
    }

    public function test_the_task_panel_sets_waiting_for_start_date_and_starts_after(): void
    {
        $a = $this->task(['title' => 'A']);
        $b = $this->task(['title' => 'B']);
        $c = $this->task(['title' => 'C']);

        $this->patch(route('tasks.update', $a), ['status' => 'waiting', 'waiting_for' => 'Dr. Lim']);
        $this->patch(route('tasks.update', $b), ['available_from' => '2026-11-15T08:00']);
        $this->assertSame('2026-11-15 08:00', $b->fresh()->available_from->timezone(self::TZ)->format('Y-m-d H:i'));

        $this->patch(route('tasks.update', $c), ['depends_on' => [$b->id]])->assertSessionHasNoErrors();
        // B waiting for C would make them wait for each other.
        $this->patch(route('tasks.update', $b), ['depends_on' => [$c->id]])->assertSessionHasErrors('depends_on');
        $this->patch(route('tasks.update', $c), ['depends_on' => [$c->id]])->assertSessionHasErrors('depends_on');

        $this->get('/tasks/waiting')->assertInertia(fn ($page) => $page->has('notReady', 3));
        $this->get(route('tasks.index', ['task' => $c->id]))->assertInertia(fn ($page) => $page
            ->where('selected.dependsOn.0.title', 'B')
            ->where('selected.candidates', fn ($c) => collect($c)->pluck('title')->sort()->values()->all() === ['A', 'B']));

        $this->patch(route('tasks.update', $c), ['depends_on' => []]);
        $this->assertSame(0, $c->dependencies()->count());
    }

    public function test_done_twice_keeps_the_score_and_undo_brings_back_the_old_status(): void
    {
        $task = $this->task(['status' => 'doing', 'due_at' => $this->local('2026-11-12 09:00')]);
        $this->post(route('tasks.complete', $task));
        $score = $task->fresh()->score_at_completion;
        $at = $task->fresh()->completed_at;
        $this->assertNotNull($score);

        $this->post(route('tasks.complete', $task));
        $this->assertSame([$score, $at->toIso8601String()], [$task->fresh()->score_at_completion, $task->fresh()->completed_at->toIso8601String()]);

        // A stale "Ready now" can't half-reopen a done task.
        $this->patch(route('tasks.update', $task), ['status' => 'todo']);
        $this->assertSame(Task::DONE, $task->fresh()->status);

        $this->post(route('tasks.reopen', $task));
        $this->assertSame('doing', $task->fresh()->status);
    }

    public function test_steps_of_a_deleted_task_are_not_found_instead_of_an_error(): void
    {
        $task = $this->task();
        $step = $task->steps()->create(['title' => 'Outline', 'position' => 0]);
        $task->delete();
        $this->patch(route('steps.update', $step), ['done' => true])->assertNotFound();
        $this->post(route('steps.promote', $step))->assertNotFound();
    }

    public function test_quick_add_does_not_misread_ordinary_words(): void
    {
        $preview = fn (string $text) => $this->postJson(route('tasks.preview'), ['text' => $text])->json('parsed');

        $this->assertSame(['Review 2 decks', null], [$preview('Review 2 decks')['title'], $preview('Review 2 decks')['due']]);
        $this->assertSame('Plan 3 marketing posts', $preview('Plan 3 marketing posts')['title']);
        $this->assertSame('Essay 31/2', $preview('Essay 31/2')['title']);
        $this->assertSame('Read 0h', $preview('Read 0h')['title']);
        $this->assertNull($preview('Read 0h')['hours']);
        $this->assertSame('2026-12-03T15:59:00Z', $preview('Quiz 3 dec')['due']);
        // 9am has already passed at 10:00, so it means tomorrow.
        $this->assertSame('2026-11-12T01:00:00Z', $preview('Call mum 9am')['due']);

        $this->post(route('tasks.store'), ['text' => 'tomorrow 3h'])->assertSessionHasErrors('text');
        $this->post(route('tasks.store'), ['text' => str_repeat('a', 256)])->assertSessionHasErrors('text');
    }

    public function test_project_days_left_counts_whole_days_in_the_users_time_zone(): void
    {
        $soon = $this->user->projects()->create(['name' => 'Soon', 'due_date' => '2026-11-13']);
        $late = $this->user->projects()->create(['name' => 'Late', 'due_date' => '2026-11-09']);
        $this->get(route('projects.show', $soon))->assertInertia(fn ($page) => $page->where('project.daysLeft', 2));
        $this->get(route('projects.show', $late))->assertInertia(fn ($page) => $page->where('project.daysLeft', -2));
    }

    public function test_monthly_repeats_keep_their_day_of_the_month(): void
    {
        config(['velocity.fixed_now' => '2027-01-31 09:00']);
        $task = $this->task(['due_at' => $this->local('2027-01-31 12:00'), 'repeat_unit' => 'month']);
        $dates = [];
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('tasks.complete', $task));
            $task = Task::where('repeated_from_id', $task->id)->firstOrFail();
            $dates[] = $task->due_at->timezone(self::TZ)->format('j M');
            config(['velocity.fixed_now' => $task->due_at->timezone(self::TZ)->subHour()->format('Y-m-d H:i')]);
        }
        $this->assertSame(['28 Feb', '31 Mar', '30 Apr'], $dates);
    }

    public function test_archive_lists_deleted_tasks_and_archived_goals(): void
    {
        $task = $this->task(['title' => 'Old idea']);
        $task->delete();
        $goal = $this->user->goals()->create(['title' => 'Learn French', 'color' => 1, 'archived_at' => now()]);
        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page
            ->where('tasks.0.title', 'Old idea')
            ->where('goals.0.title', 'Learn French'));
        $this->post(route('goals.unarchive', $goal));
        $this->get(route('tasks.archive'))->assertInertia(fn ($page) => $page->where('goals', []));
    }
}
