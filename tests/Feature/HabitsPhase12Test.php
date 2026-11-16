<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 12 in Habits: start and end dates, status, description and why, goals and area, routines, the month, stats, search. */
class HabitsPhase12Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function add(array $fields): Habit
    {
        $this->post(route('habits.store'), $fields + ['name' => 'Read', 'kind' => 'do', 'schedule' => 'daily', 'target' => 1])->assertSessionHasNoErrors();

        return Habit::latest('id')->first();
    }

    public function test_start_and_end_dates_set_when_it_is_due_and_its_status(): void
    {
        $later = $this->add(['name' => 'Gym', 'starts_on' => '2026-11-16']);
        $over = $this->add(['name' => 'Exam revision', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-10']);
        $now = $this->add(['name' => 'Read', 'ends_on' => '2026-11-30']);

        $this->get(route('habits.index', ['view' => 'all', 'habit' => $later->id]))->assertInertia(fn (Assert $p) => $p
            ->where('habits', fn ($rows) => collect($rows)->pluck('status', 'name')->all() === ['Exam revision' => 'finished', 'Gym' => 'notStarted', 'Read' => 'active'])
            ->where('habit.startsOn', '2026-11-16')
            ->where('habit.history', fn ($days) => collect($days)->firstWhere('date', '2026-11-11')['state'] === 'before'));
        $this->assertFalse($over->fresh()->isDue(now()->toImmutable()));
        $this->assertTrue($now->fresh()->isDue(now()->toImmutable()));

        $this->post(route('habits.store'), ['name' => 'Bad', 'kind' => 'do', 'schedule' => 'daily', 'target' => 1, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-10'])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_description_why_goals_and_area_and_an_edit_without_them_keeps_them(): void
    {
        $goal = $this->user->goals()->create(['title' => 'Graduate with a First']);
        $health = $this->user->goals()->create(['title' => 'Stay healthy']);
        $area = $this->user->areas()->create(['name' => 'Personal']);
        $h = $this->add(['description' => '20 pages', 'why' => 'Clear head', 'goal_ids' => [$goal->id, $health->id], 'area_id' => $area->id]);

        $this->get(route('habits.index', ['habit' => $h->id]))->assertInertia(fn (Assert $p) => $p
            ->where('habit.description', '20 pages')
            ->where('habit.why', 'Clear head')
            ->where('habit.goals.0.title', 'Graduate with a First')
            ->where('habit.area.name', 'Personal')
            ->where('habit.form.goal_ids', [$goal->id, $health->id]));

        // On the Goals map it shows under each goal it serves.
        $this->get(route('goals.index'))->assertInertia(fn (Assert $p) => $p
            ->where('goals', fn ($goals) => collect($goals)->every(fn ($g) => collect($g['habits'])->pluck('name')->all() === ['Read'])));

        // An edit that doesn't send them (an older client, the API) leaves them alone.
        $this->patch(route('habits.update', $h), ['name' => 'Read more', 'kind' => 'do', 'schedule' => 'daily', 'target' => 1])->assertSessionHasNoErrors();
        $h->refresh();
        $this->assertSame(['Read more', '20 pages', 'Clear head', $area->id, 2], [$h->name, $h->description, $h->why, $h->area_id, $h->goals()->count()]);

        // Not twice on the map: in a project of that goal, it shows under the project only.
        $run = $this->user->projects()->create(['name' => 'Run a 5K', 'goal_id' => $health->id]);
        $h->update(['project_id' => $run->id]);
        $this->get(route('goals.index'))->assertInertia(fn (Assert $p) => $p
            ->where('goals', fn ($goals) => collect(collect($goals)->firstWhere('id', $health->id)['habits'])->isEmpty()
                && collect(collect($goals)->firstWhere('id', $goal->id)['habits'])->pluck('name')->all() === ['Read more']));
    }

    public function test_the_numbers_count_partly_done_days_as_half_and_this_month_shows_every_day(): void
    {
        $h = $this->add(['name' => 'Water', 'target' => 2, 'unit' => 'glasses']);
        // 1–10 Nov (1 Nov is a Sunday): done but on the 2nd (partly) and the 3rd (missed); today, the 11th, is still going.
        foreach (['01' => 2, '02' => 1, '04' => 2, '05' => 2, '06' => 2, '07' => 2, '08' => 2, '09' => 2, '10' => 2] as $d => $count) {
            $h->entries()->create(['day' => "2026-11-{$d}", 'count' => $count]);
        }

        $this->get(route('habits.index', ['habit' => $h->id]))->assertInertia(fn (Assert $p) => $p
            ->where('habit.stats.rate', 85)          // (8 + ½) of 10
            ->where('habit.stats.done', 8)
            ->where('habit.stats.partial', 1)
            ->where('habit.stats.of', 10)
            ->where('habit.stats.bounceBacks', 1)    // the 4th, after the missed 3rd
            ->where('habit.stats.hardestDay', 2)     // Tuesdays: the 3rd missed, the 10th done
            ->where('habit.stats.average', 1.7)
            ->where('habit.stats.trend', null));     // nothing before it began to compare with

        $this->get(route('habits.index', ['view' => 'month']))->assertInertia(fn (Assert $p) => $p
            ->where('month.start', '2026-11-01')
            ->has('habits.0.days', 30)
            ->where('habits.0.days.2.state', 'missed'));
    }

    public function test_a_routine_has_a_description_and_can_be_archived(): void
    {
        $h = $this->add([]);
        $this->post(route('routines.store'), ['name' => 'Morning', 'description' => 'Before campus', 'habit_ids' => [$h->id]]);
        $routine = $this->user->routines()->sole();
        $this->assertSame('Before campus', $routine->description);

        $this->post(route('routines.archive', $routine))->assertSessionHas('toast', 'Archived “Morning”');
        $this->get(route('habits.index'))->assertInertia(fn (Assert $p) => $p->where('menu.routines', []));
        $this->get(route('habits.index', ['view' => 'archived']))->assertInertia(fn (Assert $p) => $p->where('archivedRoutines.0.name', 'Morning'));
        $this->post(route('routines.unarchive', $routine));
        $this->assertNull($routine->fresh()->archived_at);
    }
}
