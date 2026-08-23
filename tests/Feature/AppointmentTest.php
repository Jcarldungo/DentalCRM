<?php
// tests/Feature/AppointmentTest.php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_appointment_can_be_created(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'cleaning',
            'status' => 'scheduled',
        ]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 08:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('end_time');
    }

    public function test_type_must_be_a_known_value(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
            'type' => 'root-canal-surgery-extreme',
        ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_appointment_can_be_rescheduled(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
        ]);

        $response = $this->patch(route('appointments.update', $appointment), [
            'start_time' => '2026-09-02 10:00:00',
            'end_time' => '2026-09-02 10:30:00',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => '2026-09-02 10:00:00',
        ]);
    }

    public function test_appointment_status_can_be_updated(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'completed']);
    }

    public function test_events_feed_returns_appointments_in_range_shaped_for_fullcalendar(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Cruz']);
        $provider = Provider::factory()->create(['name' => 'Dr. Santos']);

        $inRange = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-05 09:00:00',
            'end_time' => '2026-09-05 09:30:00',
            'type' => 'checkup',
        ]);

        $outOfRange = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-10-05 09:00:00',
            'end_time' => '2026-10-05 09:30:00',
        ]);

        $response = $this->getJson(route('appointments.events', [
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'id' => $inRange->id,
            'title' => 'Maria Cruz — Checkup',
        ]);
    }
}
