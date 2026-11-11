<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_new_users_get_their_device_time_zone()
    {
        $form = ['name' => 'Test User', 'password' => 'password', 'password_confirmation' => 'password'];

        $this->post('/register', $form + ['email' => 'a@example.com', 'timezone' => 'America/New_York']);
        $this->assertSame('America/New_York', User::where('email', 'a@example.com')->first()->timezone);

        auth()->logout();
        $this->post('/register', $form + ['email' => 'b@example.com', 'timezone' => 'Not/AZone']);
        $this->assertSame('Asia/Kuala_Lumpur', User::where('email', 'b@example.com')->first()->timezone);
    }
}
