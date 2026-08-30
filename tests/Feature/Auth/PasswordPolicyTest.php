<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_password_under_twelve_characters(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Short1234',
            'password_confirmation' => 'Short1234',
            'registration_code' => 'test-code',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_rejects_a_password_with_no_digit(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'NoDigitsHere',
            'password_confirmation' => 'NoDigitsHere',
            'registration_code' => 'test-code',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_accepts_a_twelve_character_password_with_letters_and_numbers(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'ValidPass123',
            'password_confirmation' => 'ValidPass123',
            'registration_code' => 'test-code',
        ]);

        $this->assertAuthenticated();
    }

    public function test_profile_password_update_enforces_the_same_policy(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
