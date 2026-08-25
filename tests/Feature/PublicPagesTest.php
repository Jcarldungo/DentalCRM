<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_contact_page_passes_service_query_param_as_initial_service_prop(): void
    {
        $response = $this->get(route('contact', ['service' => 'Teeth Whitening']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Contact')
            ->where('initialService', 'Teeth Whitening')
        );
    }
}
