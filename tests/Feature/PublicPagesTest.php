<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/Home'));
    }

    public function test_services_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('services'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/Services'));
    }

    public function test_dentists_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('dentists'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/Dentists'));
    }

    public function test_about_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/About'));
    }

    public function test_contact_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/Contact'));
    }

    public function test_book_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('book'));

        $response->assertOk();
    }

    public function test_book_page_receives_a_prefilled_service_from_the_query_string(): void
    {
        $response = $this->get(route('book', ['service' => 'Teeth Whitening']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('initialService', 'Teeth Whitening')
        );
    }

    public function test_contact_page_passes_service_query_param_as_initial_service_prop(): void
    {
        $response = $this->get(route('contact', ['service' => 'Teeth Whitening']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Contact')
            ->where('initialService', 'Teeth Whitening')
        );
    }

    /**
     * An anonymous curl of the homepage used to return the whole named
     * route map — every staff URI, method, and parameter name, including
     * both DELETE endpoints. Those routes are all correctly behind `auth`,
     * so this is reconnaissance hardening rather than an authorization
     * fix, but it is exactly the map an attacker wants before attempting
     * CSRF or XSS against a staff session.
     *
     * @return array<string, array{0: string}>
     */
    public static function staffRouteProvider(): array
    {
        return [
            'patients.destroy' => ['patients.destroy'],
            'providers.destroy' => ['providers.destroy'],
            'reports.index' => ['reports.index'],
            'inventory.store' => ['inventory.store'],
            'invoice-payments.store' => ['invoice-payments.store'],
        ];
    }

    #[DataProvider('staffRouteProvider')]
    public function test_a_guest_page_does_not_publish_the_staff_route_map(string $routeName): void
    {
        $this->get(route('home'))->assertDontSee($routeName, false);
        $this->get(route('login'))->assertDontSee($routeName, false);
    }

    #[DataProvider('staffRouteProvider')]
    public function test_an_authenticated_page_still_publishes_the_full_route_map(string $routeName): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertSee($routeName, false);
    }

    /**
     * The narrow group has to cover every route a guest-reachable page
     * calls, or that page renders blank with a Ziggy error.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guestPageProvider(): array
    {
        return [
            'home' => ['home', 'book'],
            'book' => ['book', 'bookings.store'],
            'contact' => ['contact', 'inquiries.store'],
            'lookup' => ['appointments.lookup.create', 'appointments.lookup.send'],
            'login' => ['login', 'password.request'],
            'forgot password' => ['password.request', 'password.email'],
        ];
    }

    #[DataProvider('guestPageProvider')]
    public function test_a_guest_page_carries_the_routes_it_calls(string $page, string $needs): void
    {
        $this->get(route($page))->assertSee($needs, false);
    }
}
