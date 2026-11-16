<?php

namespace Tests\Feature;

use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 11 on the Planner: the month view, project and reserved time, locks, blocked bookings, events, markers. */
class PlannerPhase11Test extends TestCase
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

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    /** Booked blocks as "title: local day start–end", in time order. */
    private function booked(): array
    {
        return PlannerBlock::orderBy('starts_at')->get()->map(fn (PlannerBlock $b) => sprintf(
            '%s: %s–%s',
            $b->title(),
            $b->starts_at->timezone(self::TZ)->format('D H:i'),
            $b->ends_at->timezone(self::TZ)->format('H:i'),
        ))->all();
    }

    public function test_reserved_time_is_planned_around_and_is_not_work(): void
    {
        $this->post(route('blocks.store'), ['kind' => 'reserved', 'label' => '  Keep free ', 'date' => '2026-11-11', 'start' => '10:00', 'minutes' => 240])
            ->assertSessionHas('toast', 'Reserved “Keep free”');
        $this->task('Essay');

        // Four reserved hours don't use up the four-hour day: the essay still fits, after the reserved time and its gap.
        $this->post(route('planner.plan'), ['date' => '2026-11-11', 'span' => 'day'])->assertSessionHasNoErrors();
        $this->assertSame(['Keep free: Wed 10:00–14:00', 'Essay: Wed 14:10–15:10'], $this->booked());

        $this->get('/planner?date=2026-11-11&view=day')->assertInertia(fn (Assert $p) => $p
            ->where('blocks.0.kind', 'reserved')
            ->where('blocks.0.title', 'Keep free')
            ->where('blocks.0.taskId', null)
            ->where('blocks.0.rank', null));
        $this->get('/home')->assertInertia(fn (Assert $p) => $p->where('plan.booked', 60));

        $this->post(route('blocks.store'), ['kind' => 'reserved', 'label' => '', 'date' => '2026-11-11', 'start' => '18:00', 'minutes' => 30])
            ->assertSessionHasErrors('label');
    }

    public function test_project_time_names_the_projects_top_task_and_goes_when_the_project_is_archived(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $this->task('Ethics form', ['project_id' => $fyp->id, 'importance' => 2]);
        $this->task('Literature review', ['project_id' => $fyp->id, 'importance' => 5]);
        $this->task('Groceries', ['importance' => 5, 'urgency' => 5]);

        $this->post(route('blocks.store'), ['kind' => 'project', 'project_id' => $fyp->id, 'date' => '2026-11-12', 'start' => '14:00', 'minutes' => 180])
            ->assertSessionHas('toast', 'Booked “FYP”');
        $this->get('/planner?date=2026-11-12&view=day')->assertInertia(fn (Assert $p) => $p
            ->where('blocks.0.kind', 'project')
            ->where('blocks.0.title', 'FYP')
            ->where('blocks.0.projectId', $fyp->id)
            ->where('blocks.0.next.title', 'Literature review')
            ->where('blocks.0.next.rank', 2));

        // Someone else's project can't be booked.
        $other = User::factory()->create()->projects()->create(['name' => 'Theirs']);
        $this->post(route('blocks.store'), ['kind' => 'project', 'project_id' => $other->id, 'date' => '2026-11-12', 'start' => '09:00', 'minutes' => 60])
            ->assertSessionHasErrors('project_id');

        $fyp->update(['archived_at' => now()]);
        $this->get('/planner?date=2026-11-12&view=day')->assertInertia(fn (Assert $p) => $p->has('blocks', 0));
    }

    public function test_a_locked_block_moves_only_anyway_and_a_block_can_change_its_task(): void
    {
        $essay = $this->task('Essay');
        $slides = $this->task('Slides');
        $this->post(route('blocks.store'), ['task_id' => $essay->id, 'date' => '2026-11-12', 'start' => '09:00', 'minutes' => 60]);
        $block = PlannerBlock::sole();

        $this->patch(route('blocks.lock', $block), ['locked' => true])->assertSessionHas('toast', 'Locked “Essay”');
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '11:00'])->assertSessionHasErrors('anyway');
        $this->assertSame(['Essay: Thu 09:00–10:00'], $this->booked());
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '11:00', 'anyway' => true])->assertSessionHasNoErrors();
        $this->assertSame(['Essay: Thu 11:00–12:00'], $this->booked());
        $this->assertTrue($block->fresh()->locked);
        // Undo puts it back, lock and all.
        $this->patch(route('blocks.update', $block), session('undo')['data'])->assertSessionHasNoErrors();
        $this->assertSame(['Essay: Thu 09:00–10:00'], $this->booked());

        $this->patch(route('blocks.task', $block), ['task_id' => $slides->id])->assertSessionHas('toast', 'Thu 9:00 am is now for “Slides”');
        $this->assertSame(['Slides: Thu 09:00–10:00'], $this->booked());
        $this->patch(route('blocks.task', $block), session('undo')['data']);
        $this->assertSame($essay->id, $block->fresh()->task_id);

        $gym = $this->user->plannerBlocks()->create(['kind' => 'reserved', 'label' => 'Gym', 'starts_at' => '2026-11-12 10:00:00', 'ends_at' => '2026-11-12 11:00:00']);
        $this->patch(route('blocks.task', $gym), ['task_id' => $slides->id])->assertSessionHasErrors('task_id');
    }

    public function test_the_month_view_shows_whole_weeks_from_monday(): void
    {
        // November 2026 starts on a Sunday: six weeks, from Monday 26 October to Sunday 6 December.
        $this->get('/planner?view=month&date=2026-11-18')->assertInertia(fn (Assert $p) => $p
            ->where('view', 'month')
            ->has('days', 42)
            ->where('days.0', '2026-10-26')
            ->where('days.41', '2026-12-06')
            ->where('unbooked', [])
            ->where('free', []));

        // February 2027 starts on a Monday and has 28 days: exactly four weeks.
        $this->get('/planner?view=month&date=2027-02-10')->assertInertia(fn (Assert $p) => $p
            ->has('days', 28)
            ->where('days.0', '2027-02-01')
            ->where('days.27', '2027-02-28'));
    }
}
