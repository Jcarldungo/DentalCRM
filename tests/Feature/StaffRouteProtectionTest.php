<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A representative, parameter-free route from every controller behind
     * the ['auth', 'verified'] group. Every route in that group shares the
     * same middleware declaration, so this sample proves the group applies
     * without needing a real model for every {id} route.
     */
    private const STAFF_ROUTES = [
        ['GET', '/dashboard'],
        ['GET', '/providers'],
        ['POST', '/providers'],
        ['GET', '/patients'],
        ['POST', '/patients'],
        ['GET', '/appointments'],
        ['GET', '/appointments/events'],
        ['POST', '/appointments'],
        ['GET', '/queue'],
        ['POST', '/queue/walk-ins'],
        ['GET', '/workspace'],
        ['GET', '/reports'],
        ['GET', '/invoices'],
        ['POST', '/invoices'],
        ['GET', '/inventory'],
        ['POST', '/inventory'],
        ['GET', '/inquiries'],
    ];

    public function test_a_guest_is_redirected_to_login_from_every_staff_route(): void
    {
        foreach (self::STAFF_ROUTES as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect(route('login'));
        }
    }

    public function test_an_unverified_user_is_redirected_to_the_verification_notice_from_every_staff_route(): void
    {
        $user = User::factory()->unverified()->create();

        foreach (self::STAFF_ROUTES as [$method, $uri]) {
            $this->actingAs($user)->call($method, $uri)
                ->assertRedirect(route('verification.notice'));
        }
    }

    public function test_a_verified_staff_member_can_reach_every_index_page(): void
    {
        $user = User::factory()->create(); // verified by factory default

        $getOnlyIndexes = [
            '/dashboard', '/providers', '/patients', '/appointments',
            '/queue', '/workspace', '/reports',
            '/invoices', '/inventory', '/inquiries',
        ];

        foreach ($getOnlyIndexes as $uri) {
            $this->actingAs($user)->get($uri)->assertOk();
        }

        // AppointmentController@events requires a date range regardless of
        // auth state, so it needs its own query string to reach 200 here.
        $this->actingAs($user)
            ->get('/appointments/events?start=2026-08-01&end=2026-08-31')
            ->assertOk();
    }

    public function test_profile_stays_reachable_while_unverified(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }
}
