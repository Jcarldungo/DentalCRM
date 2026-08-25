<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_inquiries(): void
    {
        $response = $this->get(route('inquiries.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_inquiries(): void
    {
        $this->actingUser();
        Inquiry::factory()->create(['name' => 'Angela Reyes']);

        $response = $this->get(route('inquiries.index'));

        $response->assertOk();
    }

    public function test_inquiries_are_ordered_newest_first(): void
    {
        $this->actingUser();
        $older = Inquiry::factory()->create(['created_at' => now()->subDay()]);
        $newer = Inquiry::factory()->create(['created_at' => now()]);

        $response = $this->get(route('inquiries.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('inquiries.0.id', $newer->id)
            ->where('inquiries.1.id', $older->id)
        );
    }

    public function test_inquiry_can_be_marked_as_read(): void
    {
        $this->actingUser();
        $inquiry = Inquiry::factory()->create(['read_at' => null]);

        $response = $this->patch(route('inquiries.update', $inquiry), ['read' => true]);

        $response->assertRedirect();
        $this->assertNotNull($inquiry->fresh()->read_at);
    }

    public function test_guest_cannot_mark_an_inquiry_as_read(): void
    {
        $inquiry = Inquiry::factory()->create();

        $response = $this->patch(route('inquiries.update', $inquiry), ['read' => true]);

        $response->assertRedirect(route('login'));
    }
}
