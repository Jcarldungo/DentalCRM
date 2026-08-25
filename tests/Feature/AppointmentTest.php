<?php

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

    public function test_events_feed_preserves_stored_wall_clock_time_with_no_offset_shift(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();

        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-05 09:00:00',
            'end_time' => '2026-09-05 09:30:00',
        ]);

        $response = $this->getJson(route('appointments.events', [
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]));

        $response->assertOk();
        $event = collect($response->json())->firstWhere('id', $appointment->id);

        $this->assertNotNull($event);
        $this->assertStringStartsWith('2026-09-05T09:00:00', $event['start']);
        $this->assertStringStartsWith('2026-09-05T09:30:00', $event['end']);
    }

    public function test_guest_cannot_view_appointment_events(): void
    {
        $response = $this->get(route('appointments.events', [
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_create_appointment(): void
    {
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_requested_appointment_can_be_stored_without_a_time_or_provider(): void
    {
        $patient = Patient::factory()->create();

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => '2026-09-02',
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'requested',
            'start_time' => null,
            'end_time' => null,
            'provider_id' => null,
            'type' => null,
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_time_of_day' => 'morning',
        ]);
        $this->assertSame('2026-09-02', $appointment->fresh()->preferred_date->toDateString());
    }

    public function test_overlapping_appointment_for_the_same_provider_is_rejected(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Appointment::count());
    }

    public function test_back_to_back_appointments_for_the_same_provider_are_allowed(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_same_time_for_a_different_provider_is_allowed(): void
    {
        $this->actingUser();
        Appointment::factory()->create([
            'provider_id' => Provider::factory()->create()->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => Provider::factory()->create()->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_cancelled_appointment_does_not_block_its_old_slot(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'status' => 'cancelled',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_pending_request_does_not_block_a_slot(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->requested()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_saving_an_appointment_over_itself_is_not_a_conflict(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response = $this->patch(route('appointments.update', $appointment), [
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_rescheduling_onto_another_appointment_is_rejected(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);
        $moving = Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-02 09:00:00',
            'end_time' => '2026-09-02 10:00:00',
        ]);

        $response = $this->patch(route('appointments.update', $moving), [
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame('2026-09-02 09:00:00', $moving->fresh()->start_time->toDateTimeString());
    }

    public function test_a_request_cannot_be_scheduled_without_a_time(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'scheduled',
        ]);

        $response->assertSessionHasErrors(['start_time', 'end_time', 'provider_id', 'type']);
        $this->assertSame('requested', $request->fresh()->status);
    }

    public function test_a_request_can_be_confirmed_with_a_full_schedule(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();
        $provider = Provider::factory()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'scheduled',
            'provider_id' => $provider->id,
            'start_time' => '2026-09-02 09:00:00',
            'end_time' => '2026-09-02 09:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertRedirect();
        $confirmed = $request->fresh();
        $this->assertSame('scheduled', $confirmed->status);
        $this->assertSame($provider->id, $confirmed->provider_id);
        $this->assertSame('2026-09-02 09:00:00', $confirmed->start_time->toDateTimeString());
        $this->assertSame('2026-09-02 09:30:00', $confirmed->end_time->toDateTimeString());
        $this->assertSame('cleaning', $confirmed->type);
    }

    public function test_a_request_can_be_declined_without_a_time(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'declined',
        ]);

        $response->assertRedirect();
        $this->assertSame('declined', $request->fresh()->status);
    }
}
