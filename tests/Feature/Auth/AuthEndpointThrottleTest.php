<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthEndpointThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_throttled_after_six_requests_per_minute(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', ['email' => "user{$i}@example.com"]);
        }

        $this->post('/register', ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }

    public function test_reset_password_is_throttled_after_six_requests_per_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', ['email' => "user{$i}@example.com"]);
        }

        $this->post('/reset-password', ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }

    public function test_confirm_password_is_throttled_after_six_requests_per_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong']);
        }

        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_password_update_is_throttled_after_six_requests_per_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->put('/password', ['current_password' => 'wrong']);
        }

        $this->actingAs($user)->put('/password', ['current_password' => 'wrong'])
            ->assertStatus(429);
    }
}
