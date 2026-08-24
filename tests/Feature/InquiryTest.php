<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_an_inquiry(): void
    {
        $response = $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
            'service_interest' => 'Teeth Whitening',
            'message' => 'I would like to book a whitening consultation.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inquiries', [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'service_interest' => 'Teeth Whitening',
        ]);
    }

    public function test_name_email_and_message_are_required(): void
    {
        $response = $this->post(route('inquiries.store'), []);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'not-an-email',
            'message' => 'Hello',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_new_inquiry_has_no_read_at_timestamp(): void
    {
        $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'message' => 'Hello',
        ]);

        $this->assertNull(Inquiry::first()->read_at);
    }

    public function test_a_single_legitimate_submission_is_not_blocked_by_the_rate_limiter(): void
    {
        $response = $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
            'service_interest' => 'Teeth Whitening',
            'message' => 'I would like to book a whitening consultation.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inquiries', 1);
    }
}
