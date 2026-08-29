<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_reports(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_it_renders_with_the_default_range(): void
    {
        $this->actingUser();

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('meta.range', 'this_month')
            ->where('meta.start', now()->startOfMonth()->toDateString())
            ->where('meta.end', now()->toDateString())
            ->where('meta.bucket', 'day')
            ->has('revenue')
            ->has('appointments')
            ->has('patients')
        );
    }

    public function test_custom_range_requires_both_dates(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom']))
            ->assertSessionHasErrors('start');
    }

    public function test_custom_range_rejects_end_before_start(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-03-01', 'end' => '2026-02-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_custom_range_rejects_a_span_over_400_days(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_bucket_granularity_follows_the_span(): void
    {
        $this->actingUser();

        // Deterministic custom spans — a calendar-relative preset can land
        // on a knife-edge (e.g. this_quarter on the 1st of a quarter).
        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-20']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'day'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'week'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-09-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'month'));
    }

    public function test_ytd_label_is_the_year(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'ytd']))
            ->assertInertia(fn ($page) => $page->where('meta.label', (string) now()->year));
    }
}
