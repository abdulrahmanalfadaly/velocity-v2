<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\PlannerBlock;
use App\Models\User;
use App\Services\HabitStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Habits: streaks for chosen days and for "N times a week", skipped days, the day
 * boundary in the user's time zone, fixing a past day, skipping ahead, routines in
 * order, and "Plan my day" keeping a habit's time free.
 */
class HabitsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 12 Nov 2026, 07:00 in Kuala Lumpur: still Wednesday 11 Nov in UTC.
        config(['velocity.timezone' => self::TZ, 'velocity.fixed_now' => '2026-11-12 07:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    /** A habit that has existed since 1 October, with its days: date => count, or 'skip'. */
    private function habit(array $attrs, array $days = []): Habit
    {
        $h = $this->user->habits()->create($attrs + ['target' => 1]);
        $h->forceFill(['created_at' => CarbonImmutable::parse('2026-10-01 09:00', self::TZ)->utc()])->save();
        foreach ($days as $day => $v) {
            $h->entries()->create(['day' => $day, 'count' => $v === 'skip' ? 0 : $v, 'skipped' => $v === 'skip']);
        }

        return $h;
    }

    private function stats(Habit $h): HabitStats
    {
        return new HabitStats($h->fresh(['entries', 'project', 'routines']), CarbonImmutable::parse('2026-11-12', self::TZ));
    }

    public function test_a_chosen_days_streak_counts_due_days_and_skipped_days_neither_break_nor_add(): void
    {
        // Mon, Wed, Fri. Done Wed 11, Mon 9; Fri 6 skipped; done Wed 4, Mon 2; Fri 30 Oct missed.
        $gym = $this->habit(['name' => 'Gym', 'days' => [1, 3, 5]], [
            '2026-11-11' => 1, '2026-11-09' => 1, '2026-11-06' => 'skip', '2026-11-04' => 1, '2026-11-02' => 1,
            '2026-10-28' => 1, '2026-10-26' => 1, '2026-10-23' => 1,
        ]);

        $s = $this->stats($gym);
        $row = $s->row();
        $this->assertSame(4, $row['streak']);
        $this->assertSame('off', $row['group']);            // Thursday: not a gym day
        $this->assertSame('Mon, Wed, Fri', $row['label']);
        $this->assertSame(4, $s->detail()['best']);         // 23–28 Oct is three, broken by 30 Oct
        $this->assertSame(['missed', 'skipped', 'off', 'done'], array_map(
            fn ($d) => $s->state(CarbonImmutable::parse($d, self::TZ)),
            ['2026-10-30', '2026-11-06', '2026-11-07', '2026-11-09'],
        ));
        // Last 30 days (14 Oct–12 Nov): done on 7 of the 12 due days that went by; the skipped one doesn't count.
        $this->assertSame(['done' => 7, 'of' => 12, 'unit' => 'days'], $s->rate());
    }

    public function test_part_of_a_counted_target_does_not_keep_the_streak_and_today_never_breaks_it(): void
    {
        $water = $this->habit(['name' => 'Water', 'target' => 8, 'unit' => 'glasses'], [
            '2026-11-11' => 8, '2026-11-10' => 8, '2026-11-09' => 5, '2026-11-08' => 8, '2026-11-12' => 3,
        ]);

        $row = $this->stats($water)->row();
        $this->assertSame(2, $row['streak']);      // today (3 of 8) still going, 9 Nov only 5 of 8
        $this->assertSame('todo', $row['group']);
        $this->assertSame(3, $row['today']);
    }

    public function test_a_weekly_habit_counts_weeks_and_a_holiday_lowers_what_a_week_asks(): void
    {
        $run = $this->habit(['name' => 'Run', 'per_week' => 3], [
            '2026-11-09' => 1, '2026-11-10' => 1,                                   // this week: 2 of 3 so far
            '2026-11-03' => 1, '2026-11-05' => 1, '2026-11-07' => 1,                // last week: met
            '2026-10-26' => 1, '2026-10-27' => 1,                                   // a holiday week: 2 runs, 5 days skipped
            '2026-10-28' => 'skip', '2026-10-29' => 'skip', '2026-10-30' => 'skip', '2026-10-31' => 'skip', '2026-11-01' => 'skip',
            '2026-10-20' => 1,                                                      // missed: 1 of 3
        ]);

        $row = $this->stats($run)->row();
        $this->assertSame(2, $row['streak']);
        $this->assertSame('week', $row['streakUnit']);
        $this->assertSame(['done' => 2, 'need' => 3], $row['week']);
        $this->assertSame('todo', $row['group']);
        $this->assertSame('open', $this->stats($run)->state(CarbonImmutable::parse('2026-11-11', self::TZ))); // a day without a run is not a miss

        // The third run this week: done for the week, and the week counts.
        $this->post(route('habits.check', $run))->assertSessionHasNoErrors();
        $row = $this->stats($run)->row();
        $this->assertSame(3, $row['streak']);
        $this->assertSame('done', $row['group']);
    }

    public function test_today_is_the_users_own_day_and_a_past_day_can_be_fixed_with_undo(): void
    {
        $read = $this->habit(['name' => 'Read']);

        $this->post(route('habits.check', $read))->assertSessionHas('toast', 'Done: Read');
        $this->assertSame('2026-11-12', $read->entries()->firstOrFail()->day->toDateString()); // Thursday where the user is, not UTC's Wednesday

        // Forgot to tick it on Tuesday.
        $this->put(route('habits.day', $read), ['date' => '2026-11-10', 'count' => 1])->assertSessionHas('toast', 'Read · Tue 10 Nov: done');
        $undo = session('undo');
        $this->assertSame('put-habit-day', $undo['action']);
        $this->put(route('habits.day', $read), $undo['data'])->assertSessionHasNoErrors();
        $this->assertNull($read->entries()->whereDate('day', '2026-11-10')->first());

        // Only skipping can be set for a day still to come.
        $this->put(route('habits.day', $read), ['date' => '2026-11-14', 'count' => 1])->assertSessionHasErrors('date');
        $this->put(route('habits.day', $read), ['date' => '2026-11-14', 'count' => 0, 'skipped' => true])->assertSessionHasNoErrors();
    }

    public function test_skipping_until_a_date_skips_only_the_due_days_and_undo_opens_them_again(): void
    {
        $gym = $this->habit(['name' => 'Gym', 'days' => [1, 3, 5]]);

        $this->post(route('habits.skip', $gym), ['until' => '2026-11-16'])->assertSessionHas('toast', 'Skipped “Gym” until Mon 16 Nov');
        $skipped = $gym->entries()->where('skipped', true)->get()->map(fn ($e) => $e->day->toDateString())->sort()->values()->all();
        $this->assertSame(['2026-11-13', '2026-11-16'], $skipped); // Fri and Mon; Thursday isn't a gym day

        $this->post(route('habits.unskip', $gym), session('undo')['data'])->assertSessionHasNoErrors();
        $this->assertSame(0, $gym->entries()->count());
    }

    public function test_a_routine_keeps_its_order_and_only_takes_the_users_own_habits(): void
    {
        $water = $this->habit(['name' => 'Water']);
        $stretch = $this->habit(['name' => 'Stretch']);
        $cards = $this->habit(['name' => 'Flashcards']);

        $this->post(route('routines.store'), ['name' => 'Morning', 'habit_ids' => [$water->id, $stretch->id, $cards->id]])->assertSessionHasNoErrors();
        $routine = $this->user->routines()->firstOrFail();
        $order = function () use ($routine) {
            $names = [];
            $this->get(route('habits.index', ['view' => "r{$routine->id}"]))->assertInertia(function ($page) use (&$names) {
                $names = array_column($page->toArray()['props']['habits'], 'name');
            });

            return $names;
        };
        $this->assertSame(['Water', 'Stretch', 'Flashcards'], $order());

        $this->patch(route('routines.update', $routine), ['name' => 'Morning', 'habit_ids' => [$cards->id, $water->id]])->assertSessionHasNoErrors();
        $undo = session('undo');
        $this->assertSame(['Flashcards', 'Water'], $order());
        $this->patch(route('routines.update', $routine), $undo['data'])->assertSessionHasNoErrors();
        $this->assertSame(['Water', 'Stretch', 'Flashcards'], $order());

        $theirs = User::factory()->create()->habits()->create(['name' => 'Not mine', 'target' => 1]);
        $this->patch(route('routines.update', $routine), ['name' => 'Morning', 'habit_ids' => [$theirs->id]])->assertSessionHasErrors('habit_ids.0');
        $this->actingAs(User::factory()->create())->post(route('habits.check', $water))->assertNotFound();
    }

    public function test_plan_my_day_keeps_a_habits_time_free_until_it_is_done(): void
    {
        config(['velocity.fixed_now' => '2026-11-11 10:00']); // Wednesday, 10:00
        $this->post(route('habits.store'), [
            'name' => 'Walk', 'schedule' => 'daily', 'target' => 1, 'at_time' => '10:30', 'minutes' => 30,
        ])->assertSessionHasNoErrors();
        $walk = $this->user->habits()->firstOrFail();
        $this->user->tasks()->create(['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $booked = fn () => PlannerBlock::orderBy('starts_at')->get()->map(fn ($b) => $b->starts_at->timezone(self::TZ)->format('H:i'))->all();

        $this->post(route('planner.plan'), ['date' => '2026-11-11', 'span' => 'day']);
        $this->assertSame(['11:10'], $booked()); // 10:00–10:20 is too short before the walk (and its 10-minute gap)
        $this->get(route('planner.index', ['date' => '2026-11-11', 'view' => 'day']))
            ->assertInertia(fn ($page) => $page->where('habitTimes.0.title', 'Walk')->where('habitTimes.0.done', false));

        $this->post(route('habits.check', $walk));
        $this->post(route('planner.plan'), ['date' => '2026-11-11', 'span' => 'day']);
        $this->assertSame(['10:00'], $booked());

        // A habit done any days of the week has no set time.
        $this->post(route('habits.store'), ['name' => 'Swim', 'schedule' => 'weekly', 'per_week' => 2, 'target' => 1, 'at_time' => '07:00', 'minutes' => 30])
            ->assertSessionHasErrors('at_time');
    }
}
