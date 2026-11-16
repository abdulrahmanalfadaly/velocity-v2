<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 11: "See all results" can include archived things, marked as such. */
class SearchFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_things_show_only_when_asked(): void
    {
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($user);
        $user->tasks()->create(['title' => 'Survey draft', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $user->tasks()->create(['title' => 'Old survey', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => 'done', 'archived_at' => now()]);
        $user->projects()->create(['name' => 'Survey project', 'archived_at' => now()]);

        $this->get(route('search.all', ['q' => 'survey']))->assertInertia(fn (Assert $p) => $p
            ->where('archived', false)
            ->has('groups', 1)
            ->where('groups.0.items.0.title', 'Survey draft'));

        $this->get(route('search.all', ['q' => 'survey', 'archived' => 1]))->assertInertia(fn (Assert $p) => $p
            ->where('archived', true)
            ->has('groups', 2)
            ->has('groups.0.items', 2)
            ->where('groups.0.items.1.sub', 'Archived · Done')
            ->where('groups.1.items.0.sub', 'Archived · Project'));
    }
}
