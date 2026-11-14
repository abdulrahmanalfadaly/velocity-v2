<?php

namespace Tests\Feature;

use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Resizing and exact times, overlap warnings, Settings → Planner, and Free time. */
class PlannerExtrasTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur. Working hours 08:00–22:00, H = 4 hours a day.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function event(string $date, string $start, string $end, string $title = 'Data Mining lecture'): void
    {
        $this->post(route('events.store'), [
            'title' => $title, 'calendar' => 'class', 'all_day' => false, 'repeat_weekly' => false,
            'date' => $date, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    private function book(Task $task, string $date, string $start, int $minutes)
    {
        return $this->post(route('blocks.store'), ['task_id' => $task->id, 'date' => $date, 'start' => $start, 'minutes' => $minutes]);
    }

    private function local(PlannerBlock $b): string
    {
        $b->refresh();

        return $b->starts_at->timezone(self::TZ)->format('D H:i').'–'.$b->ends_at->timezone(self::TZ)->format('H:i');
    }

    public function test_a_block_can_be_resized_and_overlaps_are_only_a_warning(): void
    {
        $this->event('2026-11-12', '09:00', '11:00');
        $task = $this->task('Chapter 4');
        $this->book($task, '2026-11-12', '10:00', 60)->assertSessionHas('toast', 'Booked “Chapter 4” · overlaps Data Mining lecture (9:00–11:00 am)');
        $block = PlannerBlock::sole();
        $this->assertSame('Thu 10:00–11:00', $this->local($block)); // booked anyway

        // Moved clear of the lecture: no warning. Then made longer from its bottom edge.
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '13:00'])->assertSessionHas('toast', 'Moved “Chapter 4”');
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '13:00', 'minutes' => 90])
            ->assertSessionHas('toast', 'Resized “Chapter 4”')
            ->assertSessionHas('undo.data', ['date' => '2026-11-12', 'start' => '13:00', 'minutes' => 60, 'pinned' => true]);
        $this->assertSame('Thu 13:00–14:30', $this->local($block));

        // Exact times from the block's menu: a new start and length.
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '08:30', 'minutes' => 45])->assertSessionHas('toast', 'Changed “Chapter 4” · overlaps Data Mining lecture (9:00–11:00 am)');

        // A new event over the block says so too.
        $this->post(route('events.store'), [
            'title' => 'Stand-up', 'calendar' => 'work', 'all_day' => false, 'repeat_weekly' => false, 'date' => '2026-11-12', 'start_time' => '08:00', 'end_time' => '08:45',
        ])->assertSessionHas('toast', 'Added “Stand-up” · overlaps Chapter 4 (8:30–9:15 am)');

        // Quick add asks before adding.
        $this->postJson(route('events.overlaps'), ['date' => '2026-11-12', 'start_time' => '10:30', 'end_time' => '12:00'])
            ->assertJson(['note' => 'overlaps Data Mining lecture (9:00–11:00 am)']);
        $this->postJson(route('events.overlaps'), ['date' => '2026-11-12', 'start_time' => '15:00', 'end_time' => '16:00'])->assertJson(['note' => '']);
    }

    public function test_planning_keeps_to_the_gap_and_block_lengths_in_settings(): void
    {
        $this->put(route('planner-settings.update'), ['gap' => 0, 'shortest' => 15, 'longest' => 60])->assertSessionHasNoErrors();
        $this->put(route('planner-settings.update'), ['gap' => 10, 'shortest' => 60, 'longest' => 30])->assertSessionHasErrors('longest');
        $this->get(route('planner-settings.edit'))->assertInertia(fn ($page) => $page->component('settings/planner')->where('settings.longest', 60));

        $this->event('2026-11-12', '09:00', '10:00');
        $this->task('Essay', ['est_hours' => 2]);
        $this->post(route('planner.plan'), ['date' => '2026-11-12', 'span' => 'day'])->assertSessionHasNoErrors();
        // Hour-long blocks (the longest), right up to the lecture and right after it (no gap), then each other.
        $this->assertSame(['Thu 08:00–09:00', 'Thu 10:00–11:00'], PlannerBlock::orderBy('starts_at')->get()->map(fn ($b) => $this->local($b))->all());
    }

    public function test_free_time_is_working_hours_around_events_habit_times_and_blocks(): void
    {
        $this->event('2026-11-12', '09:00', '11:00');
        $this->book($this->task('Chapter 4'), '2026-11-12', '14:00', 60);
        $this->user->update(['planner_hours' => array_fill(0, 7, ['start' => '08:00', 'end' => '18:00'])]);

        $this->get(route('planner.index', ['view' => 'day', 'date' => '2026-11-12']))->assertInertia(fn ($page) => $page
            ->where('free.0.date', '2026-11-12')
            // 8:00–8:50, 11:10–14:00, 15:10–18:00 (a 10-minute gap around the lecture and after the block).
            ->where('free.0.slots', [
                ['start' => '2026-11-12T00:00:00Z', 'end' => '2026-11-12T00:50:00Z'],
                ['start' => '2026-11-12T03:10:00Z', 'end' => '2026-11-12T06:00:00Z'],
                ['start' => '2026-11-12T07:10:00Z', 'end' => '2026-11-12T10:00:00Z'],
            ])
            ->where('free.0.minutes', 50 + 170 + 170));

        // Today: only what's left from now (10:00).
        $this->get(route('planner.index', ['view' => 'day', 'date' => '2026-11-11']))->assertInertia(fn ($page) => $page
            ->where('free.0.slots.0.start', '2026-11-11T02:00:00Z')->where('free.0.minutes', 480));
    }
}
