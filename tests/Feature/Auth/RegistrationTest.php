<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['clinic.registration_code' => 'harborview-2026']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_screen_is_unreachable_when_no_code_is_configured(): void
    {
        config(['clinic.registration_code' => null]);

        $this->get('/register')->assertStatus(403);
        $this->post('/register', ['name' => 'Test User'])->assertStatus(403);
    }

    public function test_new_users_can_register_with_the_correct_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'registration_code' => 'harborview-2026',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_rejected_with_a_wrong_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'registration_code' => 'wrong-code',
        ]);

        $response->assertSessionHasErrors('registration_code');
        $this->assertGuest();
    }

    public function test_registration_is_rejected_with_a_missing_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertSessionHasErrors('registration_code');
        $this->assertGuest();
    }
}
