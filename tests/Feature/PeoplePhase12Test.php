<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 12 in People: nickname, location, usernames, contact order, search, date reminders, interactions, memories, Follow up. */
class PeoplePhase12Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function sara(array $extra = []): Person
    {
        $this->post(route('people.store'), $extra + ['name' => 'Sara Lim', 'nickname' => 'Saz', 'location' => 'Penang', 'contacts' => [
            ['kind' => 'username', 'label' => 'Instagram', 'value' => 'saralim'],
            ['kind' => 'email', 'label' => null, 'value' => 'sara@example.com'],
        ], 'group_ids' => []])->assertSessionHasNoErrors();

        return Person::latest('id')->first();
    }

    private function at(string $when): void
    {
        config(['velocity.fixed_now' => $when]);
    }

    /** The titles of the date reminder cards due now. */
    private function dueDates(): array
    {
        return array_column($this->getJson(route('reminders.due'))->assertOk()->json('dates'), 'title');
    }

    public function test_a_date_has_several_reminders_at_their_times_and_can_be_snoozed(): void
    {
        $sara = $this->sara();
        // Birthday on Fri 20 Nov: a week before at 8:00, and on the day at 9:00.
        $this->post(route('person-dates.store', $sara), ['kind' => 'birthday', 'month' => 11, 'day' => 20, 'reminders' => [['days' => 7, 'at' => '08:00'], ['days' => 0, 'at' => '09:00']]])
            ->assertSessionHasNoErrors();
        $date = $sara->dates()->sole();
        $this->assertSame([['days' => 7, 'at' => '08:00'], ['days' => 0, 'at' => '09:00']], $date->reminders);

        $this->at('2026-11-13 07:59');
        $this->assertSame([], $this->dueDates());
        $this->at('2026-11-13 08:00');
        $this->assertSame(['Sara Lim’s birthday'], $this->dueDates());
        $this->assertSame([], $this->dueDates()); // once

        // Snoozed for an hour: it comes back after it.
        $this->post(route('person-dates.snooze', $date), ['for' => '1h']);
        $this->at('2026-11-13 08:30');
        $this->assertSame([], $this->dueDates());
        $this->at('2026-11-13 09:01');
        $this->assertSame(['Sara Lim’s birthday'], $this->dueDates());

        $this->at('2026-11-20 09:00');
        $this->assertSame(['Sara Lim’s birthday'], $this->dueDates());

        // The old single reminder is still understood.
        $this->patch(route('person-dates.update', $date), ['kind' => 'birthday', 'month' => 11, 'day' => 20, 'remind_days' => 1]);
        $this->assertSame([['days' => 1, 'at' => '09:00']], $date->fresh()->reminderList());
    }

    public function test_interactions_have_a_time_can_be_changed_and_can_not_count(): void
    {
        $sara = $this->sara(['catch_up_days' => 14]);
        $this->post(route('catch-ups.store', $sara), ['kind' => 'met', 'on' => '2026-11-01', 'at' => '19:30', 'note' => 'Dinner']);
        $this->post(route('catch-ups.store', $sara), ['kind' => 'message', 'on' => '2026-11-10', 'counts' => false])->assertSessionHas('toast', 'Noted (doesn’t count)');

        // The one that doesn't count leaves the clock where it was: 14 days from 1 Nov.
        $this->assertSame('2026-11-15', $sara->fresh()->nextCatchUp(now()->toImmutable()->timezone('Asia/Kuala_Lumpur')->startOfDay())->toDateString());
        $this->get(route('people.index', ['person' => $sara->id]))->assertInertia(fn (Assert $p) => $p
            ->where('person.keepInTouch.last', '2026-11-01')
            ->where('person.keepInTouch.history.1.at', '19:30'));

        $dinner = $sara->catchUps()->where('note', 'Dinner')->sole();
        $this->patch(route('catch-ups.update', $dinner), ['kind' => 'call', 'on' => '2026-11-02', 'at' => '20:00', 'note' => 'Long call'])->assertSessionHas('toast', 'Saved');
        $this->assertSame(['call', '2026-11-02', '20:00'], [$dinner->fresh()->kind, $dinner->fresh()->on->toDateString(), $dinner->fresh()->at]);
        $this->patch(route('catch-ups.update', $dinner), session('undo')['data']);
        $this->assertSame('met', $dinner->fresh()->kind);
    }

    public function test_nickname_location_usernames_and_contact_order(): void
    {
        $sara = $this->sara();
        $this->assertSame(['Saz', 'Penang'], [$sara->nickname, $sara->location]);
        $this->assertSame(['@saralim', 'sara@example.com'], $sara->contacts()->orderBy('position')->pluck('value')->all());

        $this->get(route('people.index', ['person' => $sara->id]))->assertInertia(fn (Assert $p) => $p
            ->where('person.nickname', 'Saz')
            ->where('person.form.location', 'Penang')
            ->where('people.0.find', fn ($f) => str_contains($f, 'saz') && str_contains($f, '@saralim') && str_contains($f, 'penang')));

        // Ctrl K finds them by nickname.
        $this->getJson(route('search', ['q' => 'saz']))->assertJsonPath('groups.0.items.0.title', 'Sara Lim (Saz)');

        // An edit that doesn't send them (an older client) keeps nickname and location.
        $this->patch(route('people.update', $sara), ['name' => 'Sara Lim', 'contacts' => [['kind' => 'email', 'label' => null, 'value' => 'sara@example.com']], 'group_ids' => []]);
        $this->assertSame(['Saz', 'Penang'], [$sara->fresh()->nickname, $sara->fresh()->location]);
    }
}
