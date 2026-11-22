<?php

namespace Tests\Feature;

use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Plan my day / week": the booking rules. Rank order, free time around events,
 * due times, the daily limit H, working hours, pinned blocks, planning again, undo.
 */
class PlannerBlocksTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur. Working hours 08:00–22:00.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function plan(string $span = 'day', string $date = '2026-11-11', array $extra = []): void
    {
        $this->post(route('planner.plan'), ['date' => $date, 'span' => $span] + $extra)->assertSessionHasNoErrors();
    }

    /** The booked blocks as "task: local day start–end", in time order. */
    private function booked(): array
    {
        return PlannerBlock::with('task')->orderBy('starts_at')->get()->map(fn (PlannerBlock $b) => sprintf(
            '%s: %s–%s',
            $b->task->title,
            $b->starts_at->timezone(self::TZ)->format('D H:i'),
            $b->ends_at->timezone(self::TZ)->format('H:i'),
        ))->all();
    }

    private function event(string $date, string $start, string $end, array $extra = []): void
    {
        $this->post(route('events.store'), $extra + [
            'title' => 'Lecture', 'calendar' => 'class', 'all_day' => false, 'repeat_weekly' => false,
            'date' => $date, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    public function test_tasks_are_booked_in_rank_order_from_now_with_a_break_after_each_block(): void
    {
        $this->task('Second', ['importance' => 2, 'est_hours' => 1.5]);
        $this->task('First', ['importance' => 5, 'est_hours' => 1]);

        $this->plan();

        $this->assertSame(['First: Wed 10:00–11:00', 'Second: Wed 11:10–12:40'], $this->booked());
        $this->assertSame('Booked 2 h 30 min', session('toast'));
    }

    public function test_long_tasks_are_split_into_blocks_of_at_most_two_hours(): void
    {
        $this->task('Report', ['est_hours' => 3]);

        $this->plan();

        $this->assertSame(['Report: Wed 10:00–12:00', 'Report: Wed 12:10–13:10'], $this->booked());
    }

    public function test_blocks_go_around_events_with_a_gap_and_end_before_the_task_is_due(): void
    {
        $this->event('2026-11-11', '10:30', '12:00');      // free 10:00–10:20 is too short for a block
        $this->event('2026-11-11', '13:00', '14:00', ['all_day' => true]); // all-day: takes no time
        $this->task('Quiz prep', ['est_hours' => 2, 'due_at' => '2026-11-11 05:00:00']); // due 13:00 local

        $this->plan();

        $this->assertSame(['Quiz prep: Wed 12:10–13:00'], $this->booked());
        $this->assertSame('Booked 50 min · 1 didn’t fit', session('toast'));
        $this->get(route('planner.index', ['date' => '2026-11-11', 'view' => 'day']))->assertInertia(fn ($page) => $page
            ->where('unbooked.0.title', 'Quiz prep')
            ->where('unbooked.0.minutes', 70)
            ->where('unbooked.0.dueInPeriod', true));
    }

    public function test_an_overdue_task_is_booked_as_soon_as_possible(): void
    {
        $this->task('Late', ['est_hours' => 1, 'due_at' => '2026-11-10 01:00:00']); // due yesterday

        $this->plan('week');

        $this->assertSame(['Late: Wed 10:00–11:00'], $this->booked());
    }

    public function test_it_fills_the_working_hours_unless_a_daily_limit_is_set(): void
    {
        $this->task('Thesis', ['est_hours' => 6]);

        // By default as much as the working hours hold (it used to stop at the formula's H, 4 h).
        $this->plan('week');
        $this->assertSame(['Thesis: Wed 10:00–12:00', 'Thesis: Wed 12:10–14:10', 'Thesis: Wed 14:20–16:20'], $this->booked());

        // The formula's H no longer limits the planner.
        $this->user->weights()->update(['hours_per_day' => 1.5]);
        $this->plan('week');
        $this->assertSame(['Thesis: Wed 10:00–12:00', 'Thesis: Wed 12:10–14:10', 'Thesis: Wed 14:20–16:20'], $this->booked());

        // Settings → Planner → hours a day: planning again books at most that a day, the rest on the next days.
        $this->put(route('planner-settings.update'), ['gap' => 10, 'shortest' => 30, 'longest' => 120, 'daily' => 2])->assertSessionHasNoErrors();
        $this->plan('week');
        $this->assertSame(['Thesis: Wed 10:00–12:00', 'Thesis: Thu 08:00–10:00', 'Thesis: Fri 08:00–10:00'], $this->booked());
    }

    public function test_clear_plan_removes_only_the_planners_own_blocks_ahead_and_undo_brings_them_back(): void
    {
        $this->task('Thesis', ['est_hours' => 3]);
        $this->plan('week');
        $planned = $this->booked();
        $this->assertNotEmpty($planned);

        // A block you placed yourself stays.
        $mine = $this->task('Email', ['est_hours' => 0.5]);
        $this->post(route('blocks.store'), ['task_id' => $mine->id, 'date' => '2026-11-13', 'start' => '09:00', 'minutes' => 30])->assertSessionHasNoErrors();

        $this->post(route('planner.clear'), ['date' => '2026-11-11', 'span' => 'week'])->assertSessionHasNoErrors();
        $this->assertSame('Cleared 3 h of planned time', session('toast'));
        $this->assertSame(['Email: Fri 09:00–09:30'], $this->booked());

        // Undo puts the planner's blocks back.
        $undo = session('undo');
        $this->post(route('planner.unplan'), $undo['data'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([...$planned, 'Email: Fri 09:00–09:30'], $this->booked());

        // Nothing of the planner's left: says so.
        $this->post(route('planner.clear'), ['date' => '2026-11-11', 'span' => 'day']);
        $this->post(route('planner.clear'), ['date' => '2026-11-11', 'span' => 'day']);
        $this->assertSame('Nothing planned to clear', session('toast'));
    }

    public function test_a_day_that_holds_its_hours_says_so_instead_of_no_free_time(): void
    {
        $this->user->forceFill(['planner_settings' => ['daily' => 4]])->save();
        $this->task('Thesis', ['est_hours' => 4]);
        $email = $this->task('Email', ['est_hours' => 0.25, 'importance' => 1]);
        $this->plan('day');
        $this->assertSame('Booked 4 h · 1 didn’t fit', session('toast'));

        // The afternoon is free, but Wednesday already has its 4 hours of work.
        $this->plan('day', '2026-11-11', ['task_id' => $email->id]);
        $this->assertSame('Wed already has its 4 h of work: no room for “Email”', session('toast'));
    }

    public function test_working_hours_and_days_off_are_kept(): void
    {
        $hours = array_fill(0, 7, ['start' => '08:00', 'end' => '22:00']);
        $hours[2] = null;                                  // Wednesday off
        $hours[3] = ['start' => '14:00', 'end' => '16:00']; // Thursday afternoon only
        $this->put(route('planner.hours'), ['hours' => $hours])->assertSessionHasNoErrors();
        $undo = session('undo');
        $this->task('Essay', ['est_hours' => 3]);

        $this->plan('week');

        $this->assertSame(['Essay: Thu 14:00–16:00', 'Essay: Fri 08:00–09:00'], $this->booked());

        // Undo puts the old hours back.
        $this->put(route('planner.hours'), $undo['data'])->assertSessionHasNoErrors();
        $this->assertSame(array_fill(0, 7, ['start' => '08:00', 'end' => '22:00']), $this->user->fresh()->planner_hours);
        $this->put(route('planner.hours'), ['hours' => [...array_fill(0, 6, null), ['start' => '18:00', 'end' => '09:00']]])
            ->assertSessionHasErrors('hours.6.end');
    }

    public function test_a_task_that_starts_later_is_booked_from_its_start_and_waiting_tasks_are_not_booked(): void
    {
        $this->task('Later', ['est_hours' => 1, 'available_from' => '2026-11-13 02:00:00']); // Fri 10:00 local
        $this->task('Waiting', ['est_hours' => 1, 'status' => 'waiting']);

        $this->plan('week');

        $this->assertSame(['Later: Fri 10:00–11:00'], $this->booked());
    }

    public function test_planning_again_keeps_pinned_blocks_and_replaces_the_planners_own(): void
    {
        $a = $this->task('A', ['importance' => 5, 'est_hours' => 1]);
        $this->task('B', ['importance' => 2, 'est_hours' => 1]);
        $this->plan();
        $blockA = PlannerBlock::where('task_id', $a->id)->firstOrFail();

        // Moved by the user to 15:00: it is pinned there now.
        $this->patch(route('blocks.update', $blockA), ['date' => '2026-11-11', 'start' => '15:00'])->assertSessionHasNoErrors();
        $this->assertTrue($blockA->fresh()->pinned);
        $moveUndo = session('undo');

        $this->plan();
        $this->assertSame(['B: Wed 10:00–11:00', 'A: Wed 15:00–16:00'], $this->booked());

        // Undo of the move: back at 10:00, no longer pinned.
        $this->patch(route('blocks.update', $blockA), $moveUndo['data'])->assertSessionHasNoErrors();
        $this->assertSame('Wed 10:00', $blockA->fresh()->starts_at->timezone(self::TZ)->format('D H:i'));
        $this->assertFalse($blockA->fresh()->pinned);
    }

    public function test_undo_of_planning_puts_the_old_plan_back(): void
    {
        $this->task('A', ['importance' => 5, 'est_hours' => 1]);
        $this->plan();
        $before = $this->booked();
        $this->task('Urgent', ['importance' => 5, 'urgency' => 5, 'est_hours' => 1, 'due_at' => '2026-11-11 08:00:00']);

        $this->plan();
        $this->assertSame(['Urgent: Wed 10:00–11:00', 'A: Wed 11:10–12:10'], $this->booked());

        $undo = session('undo');
        $this->assertSame('unplan', $undo['action']);
        $this->post(route('planner.unplan'), $undo['data'])->assertSessionHasNoErrors();
        $this->assertSame($before, $this->booked());
        $this->assertSame(0, PlannerBlock::onlyTrashed()->count()); // the plan's own blocks are gone for good
    }

    public function test_booked_time_counts_once_and_finished_tasks_free_their_time(): void
    {
        $a = $this->task('A', ['importance' => 5, 'est_hours' => 1]);
        $b = $this->task('B', ['importance' => 2, 'est_hours' => 1]);
        $this->plan('day', '2026-11-11', ['task_id' => $b->id]); // "Book" one task: only that one
        $this->assertSame(['B: Wed 10:00–11:00'], $this->booked());

        $this->plan('week');                                        // B already has its hour: not booked twice
        $this->assertSame(['A: Wed 10:00–11:00', 'B: Wed 11:10–12:10'], $this->booked());

        // Done: A's block no longer shows or takes time, and planning again fills it.
        $this->post(route('tasks.complete', $a))->assertSessionHasNoErrors();
        $this->get(route('planner.index', ['date' => '2026-11-11', 'view' => 'day']))->assertInertia(fn ($page) => $page
            ->has('blocks', 1)
            ->where('blocks.0.title', 'B')
            ->where('unbooked', []));
    }

    public function test_a_task_dropped_on_the_grid_is_pinned_there_and_removing_it_can_be_undone(): void
    {
        $a = $this->task('A', ['est_hours' => 2]);

        $this->post(route('blocks.store'), ['task_id' => $a->id, 'date' => '2026-11-12', 'start' => '09:30', 'minutes' => 60])->assertSessionHasNoErrors();
        $block = PlannerBlock::firstOrFail();
        $this->assertTrue($block->pinned);
        $this->assertSame(['A: Thu 09:30–10:30'], $this->booked());

        $this->delete(route('blocks.destroy', $block))->assertSessionHas('undo.action', 'restore-block');
        $this->assertSame([], $this->booked());
        $this->post(route('blocks.restore', $block->id))->assertSessionHasNoErrors();
        $this->assertSame(['A: Thu 09:30–10:30'], $this->booked());

        // Someone else's block: not found.
        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('blocks.destroy', $block))->assertNotFound();
    }
}
