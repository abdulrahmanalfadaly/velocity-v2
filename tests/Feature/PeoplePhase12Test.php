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
