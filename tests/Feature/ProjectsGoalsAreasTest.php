<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Areas (add, rename, remove with Undo), project status and completing a project, All projects, and goal status / date / progress. */
class ProjectsGoalsAreasTest extends TestCase
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

    public function test_a_project_or_goal_with_things_in_it_can_be_deleted_and_undo_puts_it_all_back(): void
    {
        $goal = $this->user->goals()->create(['title' => 'Finish the FYP', 'color' => 1]);
        $fyp = $this->user->projects()->create(['name' => 'Chapter 3', 'goal_id' => $goal->id]);
        $a = $this->task('Draft', $fyp);
        $b = $this->task('Old', $fyp, ['status' => 'done', 'completed_at' => now()]);
        $note = $this->user->notes()->create(['title' => 'Ideas', 'project_id' => $fyp->id, 'body_text' => '']);
        $habit = $this->user->habits()->create(['name' => 'Write daily', 'target' => 1, 'project_id' => $fyp->id]);
        $loose = $this->task('Passport', null, ['goal_id' => $goal->id]);

        // Tasks to the Inbox; notes and habits stay, out of the project.
        $this->delete(route('projects.destroy', $fyp))->assertRedirect(route('tasks.index'))->assertSessionHas('toast', 'Deleted “Chapter 3” · 2 tasks moved to the Inbox');
        $this->assertSame([null, null, null, null], [$a->fresh()->project_id, $b->fresh()->project_id, $note->fresh()->project_id, $habit->fresh()->project_id]);
        $this->post(route('projects.restore', $fyp->id), session('undo')['data'])->assertSessionHas('toast', '“Chapter 3” is back');
        $this->assertSame([$fyp->id, $fyp->id, $fyp->id], [$a->fresh()->project_id, $note->fresh()->project_id, $habit->fresh()->project_id]);

        // Its tasks deleted with it, and back with it.
        $this->delete(route('projects.destroy', $fyp), ['tasks' => 'delete'])->assertSessionHas('toast', 'Deleted “Chapter 3” and its 2 tasks');
        $this->assertSoftDeleted($a);
        $this->post(route('projects.restore', $fyp->id), session('undo')['data']);
        $this->assertSame([null, $fyp->id], [$a->fresh()->deleted_at, $a->fresh()->project_id]);

        // A goal: its projects and tasks stay, without it.
        $this->delete(route('goals.destroy', $goal))->assertSessionHas('toast', 'Deleted goal “Finish the FYP” · its 1 project stays');
        $undo = session('undo');
        $this->assertSame([null, null], [$fyp->fresh()->goal_id, $loose->fresh()->goal_id]);
        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page->where('goals.0.id', 0));
        $this->post(route('goals.restore', $goal->id), $undo['data']);
        $this->assertSame([$goal->id, $goal->id], [$fyp->fresh()->goal_id, $loose->fresh()->goal_id]);

        // Nobody else's.
        $this->actingAs(User::factory()->create());
        $this->delete(route('projects.destroy', $fyp))->assertNotFound();
    }

    public function test_the_archive_can_be_emptied_for_good(): void
    {
        $old = $this->task('Last term', null, ['status' => 'done', 'completed_at' => now()->subDays(40), 'archived_at' => now()->subDays(30)]);
        $old->steps()->create(['title' => 'Step', 'position' => 0]);
        $kept = $this->task('Done this week', null, ['status' => 'done', 'completed_at' => now()]);
        $gone = $this->task('Deleted one');
        $gone->delete();

        $this->post(route('tasks.archive-empty'), ['what' => 'archived'])->assertSessionHas('toast', 'Deleted 1 task for good');
        $this->assertNull(Task::withTrashed()->find($old->id));
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull(Task::withTrashed()->find($gone->id));
        $this->post(route('tasks.archive-empty'), ['what' => 'deleted'])->assertSessionHas('toast', 'Deleted 1 task for good');
        $this->assertNull(Task::withTrashed()->find($gone->id));
    }

    private function task(string $title, ?Project $project = null, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'project_id' => $project?->id]);
    }

    public function test_areas_can_be_added_renamed_and_removed_with_undo(): void
    {
        $this->post(route('areas.store'), ['name' => 'Uni', 'icon' => 'cap'])->assertSessionHas('toast', 'Added area “Uni”');
        $area = Area::sole();
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'area_id' => $area->id]);
        $this->patch(route('areas.update', $area), ['name' => 'University'])->assertSessionHas('undo.data', ['name' => 'Uni']);
        $this->patch(route('areas.update', $area), ['icon' => 'rocket'])->assertSessionHasErrors('icon');

        // Removing it keeps its projects, under Other; Undo puts them back.
        $this->delete(route('areas.destroy', $area))->assertSessionHas('undo', ['action' => 'restore-area', 'id' => $area->id, 'data' => ['projects' => [$fyp->id]]]);
        $this->assertNull($fyp->fresh()->area_id);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('nav.areas', 0)->where('nav.loose.0.name', 'FYP'));
        $this->post(route('areas.restore', $area->id), ['projects' => [$fyp->id]]);
        $this->assertSame($area->id, $fyp->fresh()->area_id);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->where('nav.areas.0.name', 'University')->where('nav.areas.0.projects.0.name', 'FYP'));

        $theirs = User::factory()->create()->areas()->create(['name' => 'Theirs']);
        $this->delete(route('areas.destroy', $theirs))->assertNotFound();
    }

    public function test_a_planned_or_on_hold_projects_tasks_wait_under_not_ready(): void
    {
        $bakery = $this->user->projects()->create(['name' => 'Bakery']);
        $logo = $this->task('Logo', $bakery);
        $this->task('Essay');

        $this->post(route('projects.status', $bakery), ['status' => 'on_hold'])->assertSessionHas('toast', '“Bakery”: On hold')->assertSessionHas('undo.data', ['status' => 'active']);
        $this->get(route('tasks.waiting'))->assertInertia(fn ($page) => $page->count('tasks', 1)->where('tasks.0.title', 'Essay')
            ->where('notReady.0.id', $logo->id)->where('notReady.0.kind', 'held')->where('notReady.0.why', 'Project on hold')
            ->where('nav.loose.0.status', 'on_hold'));
        $this->post(route('projects.status', $bakery), ['status' => 'active']);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('tasks', 2));
        $this->post(route('projects.status', $bakery), ['status' => 'completed'])->assertSessionHasErrors('status');
    }

    public function test_completing_a_project_cancels_or_keeps_its_open_tasks_and_undo_reopens(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        [$a, $b] = [$this->task('Chapter 5', $fyp), $this->task('Slides', $fyp)];
        $this->task('Done one', $fyp, ['status' => Task::DONE, 'completed_at' => now()]);

        $this->post(route('projects.complete', $fyp), ['open' => 'cancel'])->assertSessionHas('toast', 'Completed “FYP” · 2 open tasks cancelled');
        $undo = session('undo');
        $this->assertSame(['completed', Task::CANCELLED, 'Project completed'], [$fyp->fresh()->status, $a->fresh()->status, $a->fresh()->cancel_reason]);
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('nav.loose', 0)->count('tasks', 0));

        $this->post(route('projects.reopen', $fyp), $undo['data']);
        $this->assertSame(['active', Task::TODO, Task::TODO], [$fyp->fresh()->status, $a->fresh()->status, $b->fresh()->status]);

        // Keep them open: the project is done, its tasks stay in the list.
        $this->post(route('projects.complete', $fyp), ['open' => 'keep'])->assertSessionHas('toast', 'Completed “FYP”');
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->count('tasks', 2));
        $this->get(route('projects.index'))->assertInertia(fn ($page) => $page->component('projects/index')->where('area', null)
            ->where('projects.0.status', 'completed')->where('projects.0.open', 2)->where('projects.0.done', 1)->where('projects.0.pct', 33)->where('projects.0.bestRank', 1));

        // An area's name in the Tasks menu opens its projects; "none" is those outside any area; someone else's area is ignored.
        $uni = $this->user->areas()->create(['name' => 'University']);
        $fyp->update(['area_id' => $uni->id]);
        $this->get(route('projects.index', ['area' => $uni->id]))->assertInertia(fn ($page) => $page->where('area', (string) $uni->id)->where('projects.0.areaId', $uni->id));
        $this->get(route('projects.index', ['area' => 'none']))->assertInertia(fn ($page) => $page->where('area', 'none'));
        $other = User::factory()->create()->areas()->create(['name' => 'Theirs']);
        $this->get(route('projects.index', ['area' => $other->id]))->assertInertia(fn ($page) => $page->where('area', null));
    }

    public function test_a_goal_has_a_status_a_target_date_and_progress_by_milestones_or_by_hand(): void
    {
        $this->post(route('goals.store'), ['title' => 'Finish the FYP', 'color' => 1, 'status' => 'active', 'target_date' => '2027-06-30', 'progress_mode' => 'milestones'])->assertSessionHasNoErrors();
        $goal = $this->user->goals()->sole();
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'goal_id' => $goal->id]);
        $fyp->milestones()->create(['title' => 'Proposal', 'done_at' => now()]);
        $fyp->milestones()->create(['title' => 'Draft']);
        $fyp->milestones()->create(['title' => 'Viva']);

        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page
            ->where('goals.0.targetDate', '2027-06-30')->where('goals.0.progressMode', 'milestones')->where('goals.0.progressPct', 33));

        $this->patch(route('goals.update', $goal), ['progress_mode' => 'manual', 'progress' => 70, 'status' => 'paused']);
        $this->get(route('goals.index'))->assertInertia(fn ($page) => $page->where('goals.0.progressPct', 70)->where('goals.0.status', 'paused'));
        // Back to counting tasks: the hand-set number goes.
        $this->patch(route('goals.update', $goal), ['progress_mode' => 'tasks', 'progress' => 70]);
        $this->assertNull($goal->fresh()->progress);
        $this->patch(route('goals.update', $goal), ['status' => 'done'])->assertSessionHasErrors('status');
    }
}
