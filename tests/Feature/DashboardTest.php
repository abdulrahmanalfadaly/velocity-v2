<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_a_brand_new_user_gets_home_and_default_weights(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/home');
        $this->get('/home')->assertOk();
        $this->get('/tasks')->assertOk();
        $this->get('/goals')->assertOk();
        $this->assertSame(['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12], $user->fresh()->weights()->weights()->toPercentages());
    }
}
