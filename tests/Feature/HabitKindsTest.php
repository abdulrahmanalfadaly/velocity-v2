<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\User;
use App\Services\HabitStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Habit kinds (minutes, limit, avoid), "N times a month" and "every N days", pausing, and notes on check-ins. */
class HabitKindsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
        $this->today = CarbonImmutable::parse('2026-11-11', self::TZ);
    }

    /** A habit made on a given day, with some days filled in ("2026-11-09" => 3, or 'skip'). */
    private function habit(array $attrs, string $since, array $days = []): Habit
    {
        $habit = $this->user->habits()->create($attrs + ['name' => 'Habit', 'target' => 1]);
        $habit->forceFill(['created_at' => CarbonImmutable::parse("{$since} 08:00", self::TZ)->utc()])->save();
        foreach ($days as $day => $count) {
            $habit->entries()->create(['day' => $day, 'count' => $count === 'skip' ? 0 : $count, 'skipped' => $count === 'skip']);
        }

        return $habit;
    }

    private function stats(Habit $habit): HabitStats
    {
        return new HabitStats($habit->fresh(['entries', 'pauses']), $this->today);
    }

    private function state(Habit $habit, string $day): string
    {
        return $this->stats($habit)->state(CarbonImmutable::parse($day, self::TZ));
    }

    public function test_a_limit_keeps_a_day_with_nothing_logged_and_misses_one_that_went_over(): void
    {
        $coffee = $this->habit(['name' => 'Coffee', 'kind' => 'limit', 'target' => 2, 'unit' => 'cups'], '2026-11-06', ['2026-11-08' => 1, '2026-11-10' => 3]);
        $this->assertSame(['done', 'done', 'done', 'missed', 'todo'], array_map(fn ($d) => $this->state($coffee, $d), ['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11']));
        $row = $this->stats($coffee)->row();
        $this->assertSame([0, 'todo', false, false], [$row['streak'], $row['group'], $row['overToday'], $row['doneToday']]);

        // Each tap logs one more; the third goes over.
        $this->post(route('habits.check', $coffee))->assertSessionHas('toast', 'Coffee: 1 of at most 2');
        $this->post(route('habits.check', $coffee));
        $this->post(route('habits.check', $coffee))->assertSessionHas('toast', 'Coffee: 3 of at most 2 · over the limit');
        $this->assertTrue($this->stats($coffee)->row()['overToday']);
        $this->assertSame('missed', $this->state($coffee, '2026-11-11'));
    }

    public function test_avoid_counts_the_days_kept_and_it_happened_breaks_the_streak(): void
    {
        $smoking = $this->habit(['name' => 'No smoking', 'kind' => 'avoid'], '2026-11-06');
        // Nothing logged: the five days before today were kept; today is still going.
        $this->assertSame(5, $this->stats($smoking)->row()['streak']);

        $this->post(route('habits.check', $smoking))->assertSessionHas('toast', 'No smoking: it happened today');
        $this->post(route('habits.check', $smoking))->assertSessionHas('toast', 'No smoking: kept today');
        $this->put(route('habits.day', $smoking), ['date' => '2026-11-09', 'count' => 1])->assertSessionHas('toast', 'No smoking · Mon 9 Nov: it happened');
        $this->assertSame(1, $this->stats($smoking)->row()['streak']);
    }

    public function test_a_minutes_habit_fills_with_a_tap_or_takes_an_amount(): void
    {
        $read = $this->habit(['name' => 'Read', 'kind' => 'minutes', 'target' => 20, 'unit' => 'min'], '2026-11-10');
        $this->post(route('habits.check', $read), ['count' => 10])->assertSessionHas('toast', 'Read: 10 of 20 min');
        $this->assertSame(['partial', 'todo'], [$this->state($read, '2026-11-11'), $this->stats($read)->row()['group']]);
        $this->post(route('habits.check', $read), ['count' => 0]);
        $this->post(route('habits.check', $read))->assertSessionHas('toast', 'Read: 20 of 20 min');
        $this->assertSame('done', $this->stats($read)->row()['group']);
    }

    public function test_times_a_month_ask_for_their_share_of_the_days_and_count_months_in_a_row(): void
    {
        // Began on 16 Oct: October asks for its share (4 × 16 / 31, rounded up = 3).
        $gym = $this->habit(['name' => 'Gym', 'per_month' => 4], '2026-10-16', ['2026-10-20' => 1, '2026-10-25' => 1, '2026-10-30' => 1, '2026-11-02' => 1]);
        $row = $this->stats($gym)->row();
        $this->assertSame([['done' => 1, 'need' => 4], 'todo', 1, 'month', '4 times a month'], [$row['month'], $row['group'], $row['streak'], $row['streakUnit'], $row['label']]);
        $this->assertSame('open', $this->state($gym, '2026-11-09')); // a day without a run isn't a miss

        foreach (['2026-11-05', '2026-11-08', '2026-11-11'] as $d) {
            $gym->entries()->create(['day' => $d, 'count' => 1]);
        }
        $row = $this->stats($gym)->row();
        $this->assertSame(['done', 2], [$row['group'], $row['streak']]);
        $this->assertSame(['done' => 2, 'of' => 2, 'unit' => 'months'], $this->stats($gym)->rate());
    }

    public function test_every_n_days_counts_from_its_start_date(): void
    {
        $this->post(route('habits.store'), ['name' => 'Water plants', 'schedule' => 'every', 'every_days' => 3, 'starts_on' => '2026-11-05', 'target' => 1])->assertSessionHasNoErrors();
        $plants = Habit::where('name', 'Water plants')->sole();
        $plants->forceFill(['created_at' => CarbonImmutable::parse('2026-11-05 08:00', self::TZ)->utc()])->save();
        $plants->entries()->create(['day' => '2026-11-05', 'count' => 1]);
        $plants->entries()->create(['day' => '2026-11-08', 'count' => 1]);

        $this->assertSame(['done', 'off', 'off', 'done', 'off', 'off', 'todo'], array_map(fn ($d) => $this->state($plants, $d), [
            '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11',
        ]));
        $row = $this->stats($plants)->row();
        $this->assertSame([2, 'Every 3 days', true], [$row['streak'], $row['label'], $row['dueToday']]);
    }

    public function test_a_pause_keeps_the_streak_and_leaves_today(): void
    {
        $run = $this->habit(['name' => 'Run'], '2026-11-01', array_fill_keys(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-10'], 1));
        $run->pauses()->create(['from' => '2026-11-04', 'until' => '2026-11-09']);
        $this->assertSame(['paused', 'done'], [$this->state($run, '2026-11-06'), $this->state($run, '2026-11-10')]);
        $this->assertSame(4, $this->stats($run)->row()['streak']);

        // Paused from today: out of Today, off the Planner; resuming the same day takes the pause away.
        $this->post(route('habits.pause', $run))->assertSessionHas('toast', 'Paused “Run”');
        $row = $this->stats($run)->row();
        $this->assertSame(['paused', '2026-11-11', false], [$row['group'], $row['pausedSince'], $row['dueToday']]);
        $this->post(route('habits.resume', $run))->assertSessionHas('toast', '“Run” is back');
        $this->assertSame(1, $run->pauses()->count());

        // Paused until a day, and Undo of a pause.
        $this->post(route('habits.pause', $run), ['until' => '2026-11-20'])->assertSessionHas('toast', 'Paused “Run” until Fri 20 Nov');
        $this->post(route('habits.put-pause', $run), session('undo')['data']);
        $this->assertSame(1, $run->pauses()->count());
    }

    public function test_a_note_on_a_check_in_is_kept_even_on_a_day_not_done(): void
    {
        $run = $this->habit(['name' => 'Run'], '2026-11-01');
        $this->put(route('habits.day', $run), ['date' => '2026-11-10', 'count' => 1, 'note' => 'Ran 5 km, knee okay']);
        $this->put(route('habits.day', $run), ['date' => '2026-11-09', 'count' => 0, 'note' => 'Rain']);
        $this->assertSame(2, $run->entries()->count());
        $this->assertSame([['date' => '2026-11-10', 'note' => 'Ran 5 km, knee okay', 'count' => 1, 'skipped' => false], ['date' => '2026-11-09', 'note' => 'Rain', 'count' => 0, 'skipped' => false]],
            $this->stats($run)->detail()['notes']);

        // A tap keeps the note; clearing the note on a day not done removes the day.
        $this->post(route('habits.check', $run));
        $this->put(route('habits.day', $run), ['date' => '2026-11-09', 'count' => 0, 'note' => '']);
        $this->assertSame(['2026-11-10', '2026-11-11'], $run->entries()->orderBy('day')->get()->map(fn ($e) => $e->day->toDateString())->all());
    }

    public function test_the_dialog_keeps_limits_and_avoiding_to_daily_schedules(): void
    {
        $base = ['name' => 'Snacks', 'kind' => 'limit', 'target' => 2];
        $this->post(route('habits.store'), $base + ['schedule' => 'weekly', 'per_week' => 3])->assertSessionHasErrors('schedule');
        $this->post(route('habits.store'), $base + ['schedule' => 'daily', 'at_time' => '18:00', 'minutes' => 30])->assertSessionHasErrors('at_time');
        $this->post(route('habits.store'), ['name' => 'No sugar', 'kind' => 'avoid', 'schedule' => 'days', 'days' => [1, 2, 3, 4, 5]])->assertSessionHasNoErrors();
        $this->assertSame(['avoid', 1, null], [Habit::where('name', 'No sugar')->sole()->kind, Habit::where('name', 'No sugar')->sole()->target, Habit::where('name', 'No sugar')->sole()->unit]);
        $this->post(route('habits.store'), ['name' => 'Read', 'kind' => 'minutes', 'schedule' => 'monthly', 'per_month' => 10, 'target' => 30])->assertSessionHasNoErrors();
        $this->assertSame(['min', 10], [Habit::where('name', 'Read')->sole()->unit, Habit::where('name', 'Read')->sole()->per_month]);
    }
}
