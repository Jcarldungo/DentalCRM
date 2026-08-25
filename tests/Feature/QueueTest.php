<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_a_scheduled_appointment_can_be_checked_in_via_the_existing_update_endpoint(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'checked_in',
        ]);

        $response->assertRedirect();
        $this->assertSame('checked_in', $appointment->fresh()->status);
    }

    public function test_a_checked_in_appointment_can_start_treatment(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'checked_in']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'in_treatment',
        ]);

        $response->assertRedirect();
        $this->assertSame('in_treatment', $appointment->fresh()->status);
    }

    public function test_an_in_treatment_appointment_can_be_completed(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'in_treatment']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_a_scheduled_appointment_can_be_marked_no_show(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'no_show',
        ]);

        $response->assertRedirect();
        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_checked_in_and_in_treatment_still_occupy_their_provider_slot(): void
    {
        $this->actingUser();
        $provider = \App\Models\Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'status' => 'checked_in',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => \App\Models\Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Appointment::count());
    }
}
