<?php

namespace Tests\Feature;

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
