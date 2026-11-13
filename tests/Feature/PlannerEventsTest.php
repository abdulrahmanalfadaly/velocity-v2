<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Classes and events on the Planner: one-off, weekly until a date, skipped weeks, undo. */
class PlannerEventsTest extends TestCase
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

    private function add(array $fields): Event
    {
        $this->post(route('events.store'), $fields + ['calendar' => 'class', 'all_day' => false, 'repeat_weekly' => false])->assertSessionHasNoErrors();

        return Event::latest('id')->firstOrFail();
    }

    /** The occurrences the Planner shows for a week (or day). */
    private function shown(string $date, string $view = 'week'): array
    {
        $events = [];
        $this->get(route('planner.index', ['date' => $date, 'view' => $view]))->assertOk()
            ->assertInertia(function ($page) use (&$events) {
                $page->component('planner/index');
                $events = $page->toArray()['props']['events'];
            });

        return $events;
    }

    public function test_an_event_is_saved_in_local_time_and_shows_on_its_day(): void
    {
        $e = $this->add(['title' => 'Data Mining lecture', 'date' => '2026-11-12', 'start_time' => '11:00', 'end_time' => '12:30', 'location' => 'Room B2-04']);
        $this->assertSame('2026-11-12 03:00', $e->starts_at->format('Y-m-d H:i')); // stored in UTC

        $week = $this->shown('2026-11-11');
        $this->assertSame(['Data Mining lecture'], array_column($week, 'title'));
        $this->assertSame(['2026-11-12T03:00:00Z', '2026-11-12T04:30:00Z', 'Room B2-04'], [$week[0]['start'], $week[0]['end'], $week[0]['location']]);
        $this->assertSame('11:00', $week[0]['form']['start_time']);
        $this->assertSame([], $this->shown('2026-11-11', 'day'));

        $this->get(route('planner.index', ['date' => '2026-11-11']))->assertInertia(fn ($page) => $page
            ->where('days', ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'])
            ->where('busy', ['2026-11-12'])
            ->where('calendars.class', 1));
    }

    public function test_a_weekly_class_repeats_until_its_last_week_and_one_week_can_be_skipped(): void
    {
        $e = $this->add(['title' => 'Software Testing', 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '11:00', 'repeat_weekly' => true, 'repeat_until' => '2026-11-23']);

        $this->assertSame(['2026-11-09'], array_column($this->shown('2026-11-11'), 'date'));
        $this->assertSame(['2026-11-23'], array_column($this->shown('2026-11-25'), 'date'));
        $this->assertSame([], $this->shown('2026-12-02')); // after its last week

        // A holiday: only that week goes, with Undo.
        $this->delete(route('events.destroy', $e), ['scope' => 'one', 'date' => '2026-11-16'])->assertSessionHas('toast', 'Removed “Software Testing” on Mon 16 Nov');
        $undo = session('undo');
        $this->assertSame([], $this->shown('2026-11-18'));
        $this->assertSame(['2026-11-23'], array_column($this->shown('2026-11-25'), 'date'));
        $this->post(route('events.unskip', $e), $undo['data']);
        $this->assertSame(['2026-11-16'], array_column($this->shown('2026-11-18'), 'date'));

        // Every week goes, then comes back.
        $this->delete(route('events.destroy', $e))->assertSessionHas('undo', fn ($u) => $u['action'] === 'restore-event');
        $this->assertSame([], $this->shown('2026-11-11'));
        $this->post(route('events.restore', $e->id));
        $this->assertCount(1, $this->shown('2026-11-11'));
    }

    public function test_a_weekly_class_keeps_its_local_time_when_the_clocks_change(): void
    {
        $this->user->update(['timezone' => 'Europe/London']);
        // British Summer Time ends on Sun 25 Oct 2026.
        $this->add(['title' => 'Seminar', 'date' => '2026-10-19', 'start_time' => '09:00', 'end_time' => '10:00', 'repeat_weekly' => true]);
        $before = $this->shown('2026-10-21')[0];
        $after = $this->shown('2026-10-28')[0];
        $this->assertSame(['2026-10-19T08:00:00Z', '2026-10-26T09:00:00Z'], [$before['start'], $after['start']]);
    }

    public function test_overnight_and_all_day_events(): void
    {
        $shift = $this->add(['title' => 'Café shift', 'calendar' => 'work', 'date' => '2026-11-13', 'start_time' => '19:00', 'end_time' => '01:00']);
        $this->assertSame('2026-11-14 01:00', $shift->ends_at->timezone(self::TZ)->format('Y-m-d H:i'));

        $trip = $this->add(['title' => 'Field trip', 'calendar' => 'personal', 'all_day' => true, 'date' => '2026-11-12', 'end_date' => '2026-11-13']);
        $this->assertSame(['2026-11-12 00:00', '2026-11-14 00:00'], [$trip->starts_at->timezone(self::TZ)->format('Y-m-d H:i'), $trip->ends_at->timezone(self::TZ)->format('Y-m-d H:i')]);
        $this->assertSame(['2026-11-13', null], [$trip->formValues(self::TZ)['end_date'], $trip->formValues(self::TZ)['start_time']]);

        // The shift is on Saturday's day view too, because it runs past midnight.
        $this->assertSame(['Café shift'], array_column($this->shown('2026-11-14', 'day'), 'title'));
    }

    public function test_editing_can_be_undone_and_bad_input_is_refused(): void
    {
        $e = $this->add(['title' => 'Gym', 'calendar' => 'personal', 'date' => '2026-11-12', 'start_time' => '18:00', 'end_time' => '19:00']);
        $this->patch(route('events.update', $e), ['title' => 'Gym (legs)', 'calendar' => 'personal', 'date' => '2026-11-12', 'start_time' => '18:30', 'end_time' => '19:30', 'all_day' => false, 'repeat_weekly' => false]);
        $this->assertSame('Gym (legs)', $e->fresh()->title);
        $undo = session('undo');
        $this->patch(route('events.update', $e), $undo['data'])->assertSessionHasNoErrors();
        $this->assertSame(['Gym', '18:00'], [$e->fresh()->title, $e->fresh()->formValues(self::TZ)['start_time']]);

        $base = ['title' => 'X', 'calendar' => 'class', 'date' => '2026-11-12', 'all_day' => false];
        $this->post(route('events.store'), $base)->assertSessionHasErrors('start_time');
        $this->post(route('events.store'), $base + ['start_time' => '10:00', 'end_time' => '10:00'])->assertSessionHasErrors('end_time');
        $this->post(route('events.store'), ['calendar' => 'party'] + $base + ['start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasErrors('calendar');
        $this->post(route('events.store'), $base + ['start_time' => '10:00', 'end_time' => '11:00', 'repeat_weekly' => true, 'repeat_until' => '2026-11-01'])->assertSessionHasErrors('repeat_until');
    }

    public function test_nobody_else_sees_or_changes_your_events(): void
    {
        $e = $this->add(['title' => 'Mine', 'date' => '2026-11-12', 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->actingAs(User::factory()->create(['timezone' => self::TZ]));
        $this->assertSame([], $this->shown('2026-11-11'));
        $this->patch(route('events.update', $e), ['title' => 'Theirs', 'calendar' => 'class', 'date' => '2026-11-12', 'start_time' => '09:00', 'end_time' => '10:00'])->assertNotFound();
        $this->delete(route('events.destroy', $e))->assertNotFound();
        $this->post(route('events.restore', $e->id))->assertNotFound();
        $this->assertSame('Mine', $e->fresh()->title);
    }
}
