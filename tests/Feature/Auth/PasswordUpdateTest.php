<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewSecurePass123',
                'password_confirmation' => 'NewSecurePass123',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('NewSecurePass123', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');
    }

    public function test_updating_the_password_rotates_the_remember_token(): void
    {
        $user = User::factory()->create();
        $originalToken = $user->remember_token;

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'NewSecurePass123',
            'password_confirmation' => 'NewSecurePass123',
        ]);

        $this->assertNotSame($originalToken, $user->fresh()->remember_token);
    }

    public function test_updating_the_password_keeps_the_current_session_authenticated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $response = $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'NewSecurePass123',
            'password_confirmation' => 'NewSecurePass123',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        // The same session — no fresh actingAs(), no session manipulation —
        // must still be authenticated after its own password change.
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_sibling_session_is_logged_out_after_a_password_change(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldSecurePass123')]);

        // AuthenticateSession stores the user's literal current password
        // hash string in the session (not a fresh re-hash of the plaintext
        // — bcrypt salts randomly, so two Hash::make() calls on the same
        // password never produce equal strings). Capture the real hash so
        // priming the sibling session matches what AuthenticateSession
        // would have stored on a real first request, then leave it
        // untouched while the password changes elsewhere.
        $oldPasswordHash = $user->password;

        $this->actingAs($user)
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->get('/dashboard')
            ->assertOk();

        $user->forceFill(['password' => Hash::make('NewSecurePass456')])->save();

        $response = $this->actingAs($user)
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->get('/dashboard');

        $response->assertRedirect(route('login'));
    }
}
