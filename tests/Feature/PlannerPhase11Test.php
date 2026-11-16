<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    public function test_a_booked_task_that_cant_be_worked_on_says_why(): void
    {
        $email = $this->task('Email Dr. Lim');
        $survey = $this->task('Pilot the survey');
        $survey->dependencies()->attach($email->id);
        $ready = $this->task('Ready task');
        foreach ([[$survey, '09:00'], [$ready, '11:00']] as [$t, $at]) {
            $this->post(route('blocks.store'), ['task_id' => $t->id, 'date' => '2026-11-12', 'start' => $at, 'minutes' => 60]);
        }

        $this->get('/planner?date=2026-11-12&view=day')->assertInertia(fn (Assert $p) => $p
            ->where('blocks.0.title', 'Pilot the survey')
            ->where('blocks.0.blocked', ['kind' => 'blocked', 'why' => 'Starts after “Email Dr. Lim”'])
            ->where('blocks.1.blocked', null));

        $ready->update(['status' => Task::WAITING, 'waiting_for' => 'Dr. Lim']);
        $this->get('/planner?date=2026-11-12&view=day')->assertInertia(fn (Assert $p) => $p
            ->where('blocks.1.blocked.kind', 'waiting')
            ->where('blocks.1.blocked.why', 'Waiting for Dr. Lim'));
    }

    private function lecture(array $extra = []): Event
    {
        $this->post(route('events.store'), $extra + [
            'title' => 'Data Mining', 'calendar' => 'class', 'all_day' => false, 'repeat_weekly' => true,
            'date' => '2026-11-12', 'start_time' => '09:00', 'end_time' => '11:00',
        ])->assertSessionHasNoErrors();

        return Event::latest('id')->first();
    }

    /** Each time the event happens between two local dates, as "Thu 12 Nov 09:00–11:00" (cancelled ones marked). */
    private function times(Event $e, string $from = '2026-11-09', string $to = '2026-11-30'): array
    {
        $e->refresh();

        return array_map(fn ($o) => $o['start']->timezone(self::TZ)->format('D j M H:i').'–'.$o['end']->timezone(self::TZ)->format('H:i').($o['cancelled'] ? ' cancelled' : ''),
            $e->occurrences(CarbonImmutable::parse($from, self::TZ)->utc(), CarbonImmutable::parse($to, self::TZ)->utc(), self::TZ, true));
    }

    public function test_a_weekly_event_moves_every_week_or_only_one_week(): void
    {
        $e = $this->lecture();
        $this->post(route('events.cancel', $e), ['date' => '2026-11-26', 'cancelled' => true]);

        // Every week: one day later and an hour later, with the cancelled week moving along.
        $this->patch(route('events.move', $e), ['occurrence' => '2026-11-19', 'date' => '2026-11-20', 'start' => '10:00', 'minutes' => 90, 'scope' => 'all'])
            ->assertSessionHas('toast', 'Moved “Data Mining” every week');
        $this->assertSame(['Fri 13 Nov 10:00–11:30', 'Fri 20 Nov 10:00–11:30', 'Fri 27 Nov 10:00–11:30 cancelled'], $this->times($e));
        $this->patch(route('events.move', $e), session('undo')['data'])->assertSessionHasNoErrors();
        $this->assertSame(['Thu 12 Nov 09:00–11:00', 'Thu 19 Nov 09:00–11:00', 'Thu 26 Nov 09:00–11:00 cancelled'], $this->times($e));

        // Only this week: that week is skipped and a one-off copy takes its place; Undo puts it back.
        $this->patch(route('events.move', $e), ['occurrence' => '2026-11-19', 'date' => '2026-11-19', 'start' => '14:00', 'minutes' => 120, 'scope' => 'one'])
            ->assertSessionHas('toast', 'Moved “Data Mining” on Thu 19 Nov');
        $this->assertSame(['Thu 12 Nov 09:00–11:00', 'Thu 26 Nov 09:00–11:00 cancelled'], $this->times($e));
        $copy = Event::latest('id')->first();
        $this->assertSame(['Data Mining', false, ['Thu 19 Nov 14:00–16:00']], [$copy->title, $copy->repeat_weekly, $this->times($copy)]);
        $this->post(route('events.unmove', $e), session('undo')['data']);
        $this->assertSame(3, count($this->times($e)));
        $this->assertNull(Event::find($copy->id));
    }

    public function test_a_locked_event_moves_only_anyway_and_a_cancelled_day_takes_no_time(): void
    {
        $e = $this->lecture(['repeat_weekly' => false, 'locked' => true]);
        $this->assertTrue($e->locked);
        $this->patch(route('events.move', $e), ['occurrence' => '2026-11-12', 'date' => '2026-11-12', 'start' => '13:00', 'minutes' => 120])
            ->assertSessionHasErrors('anyway');
        $this->patch(route('events.move', $e), ['occurrence' => '2026-11-12', 'date' => '2026-11-11', 'start' => '13:00', 'minutes' => 120, 'anyway' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Wed 11 Nov 13:00–15:00'], $this->times($e));

        // Cancelled: still on the Planner, crossed out, and planning may use its time; Review counts it.
        $this->post(route('events.cancel', $e), ['date' => '2026-11-11', 'cancelled' => true])->assertSessionHas('toast', 'Cancelled “Data Mining”');
        $this->get('/planner?date=2026-11-11&view=day')->assertInertia(fn (Assert $p) => $p
            ->where('events.0.cancelled', true)
            ->where('events.0.locked', true));
        $this->user->update(['planner_hours' => array_fill(0, 7, ['start' => '13:00', 'end' => '15:00'])]);
        $this->task('Essay', ['est_hours' => 2]);
        $this->post(route('planner.plan'), ['date' => '2026-11-11', 'span' => 'day']);
        $this->assertSame(['Essay: Wed 13:00–15:00'], $this->booked());
        $this->get('/review?view=day&date=2026-11-11')->assertInertia(fn (Assert $p) => $p->where('report.events.cancelled', 1));
    }

    public function test_markers_for_projects_milestones_goals_habits_and_catch_ups(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'due_date' => '2026-11-13']);
        $fyp->milestones()->create(['title' => 'Chapter 2 draft', 'due_date' => '2026-11-12']);
        $fyp->milestones()->create(['title' => 'Done already', 'due_date' => '2026-11-12', 'done_at' => now()]);
        $this->user->goals()->create(['title' => 'Graduate with a First', 'target_date' => '2026-11-15']);
        $this->user->habits()->create(['name' => 'Read', 'kind' => 'do', 'target' => 1, 'days' => [4]]);          // Thursdays, no set time
        $this->user->habits()->create(['name' => 'No sugar', 'kind' => 'avoid', 'target' => 0]);                  // avoid: no marker
        $this->user->habits()->create(['name' => 'Gym', 'kind' => 'do', 'target' => 1, 'at_time' => '18:00', 'minutes' => 45]); // has its time on the grid
        $ali = $this->user->people()->create(['name' => 'Ali', 'catch_up_days' => 14]);
        $ali->forceFill(['created_at' => '2026-10-20 00:00:00'])->save(); // due 3 Nov: overdue, so shown today

        $this->get('/planner?date=2026-11-11&view=week')->assertInertia(fn (Assert $p) => $p->where('markers', [
            ['key' => "p{$fyp->id}", 'date' => '2026-11-13', 'kind' => 'project', 'title' => 'FYP due', 'href' => "/projects/{$fyp->id}"],
            ['key' => 'm'.$fyp->milestones()->where('title', 'Chapter 2 draft')->value('id'), 'date' => '2026-11-12', 'kind' => 'milestone', 'title' => 'Chapter 2 draft', 'href' => "/projects/{$fyp->id}"],
            ['key' => 'g'.$this->user->goals()->value('id'), 'date' => '2026-11-15', 'kind' => 'goal', 'title' => 'Graduate with a First', 'href' => '/goals?focus=g'.$this->user->goals()->value('id')],
            ['key' => 'hm'.$this->user->habits()->where('name', 'Read')->value('id').':2026-11-12', 'date' => '2026-11-12', 'kind' => 'habit', 'title' => 'Read', 'href' => '/habits?habit='.$this->user->habits()->where('name', 'Read')->value('id'), 'done' => false],
            ['key' => "c{$ali->id}", 'date' => '2026-11-11', 'kind' => 'catchup', 'title' => 'Catch up with Ali', 'href' => "/people?person={$ali->id}"],
        ]));
        // The month leaves habits out.
        $this->get('/planner?date=2026-11-11&view=month')->assertInertia(fn (Assert $p) => $p->has('markers', 4));
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
