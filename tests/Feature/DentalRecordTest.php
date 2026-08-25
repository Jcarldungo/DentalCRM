<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DentalRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_dental_record_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $record = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'type' => 'consultation',
            'notes' => 'Test note',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $record->patient->id);
        $this->assertSame($provider->id, $record->provider->id);
        $this->assertSame($appointment->id, $record->appointment->id);
        $this->assertSame($user->id, $record->creator->id);
        $this->assertNull($record->updated_at);
    }

    public function test_patient_dental_records_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->dentalRecords;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_dental_records(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('dental_records', ['id' => $record->id]);
    }

    public function test_deleting_a_provider_nulls_the_dental_records_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($record->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_dental_records_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($record->fresh()->appointment_id);
    }

    public function test_guest_cannot_view_a_patients_detail_page(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->get(route('patients.show', $patient));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_create_a_dental_record(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Test note',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_dental_record_can_be_created_with_only_notes(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Patient reports mild sensitivity.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DentalRecord::count());
        $record = DentalRecord::first();
        $this->assertSame($patient->id, $record->patient_id);
        $this->assertSame('consultation', $record->type);
        $this->assertSame('Patient reports mild sensitivity.', $record->notes);
        $this->assertNull($record->provider_id);
        $this->assertNull($record->appointment_id);
        $this->assertSame($user->id, $record->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Note',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, DentalRecord::first()->created_by);
    }

    public function test_a_dental_record_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'procedure',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'procedure' => 'Composite filling, tooth #14.',
        ]);

        $response->assertRedirect();
        $record = DentalRecord::first();
        $this->assertSame($provider->id, $record->provider_id);
        $this->assertSame($appointment->id, $record->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'appointment_id' => $otherPatientsAppointment->id,
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'provider_id' => 999999,
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_an_invalid_type_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'not-a-real-type',
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_no_clinical_content_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
        ]);

        $response->assertSessionHasErrors('clinical_content');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_only_whitespace_clinical_content_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'examination' => '   ',
            'diagnosis' => "\n\t",
            'procedure' => '',
            'notes' => '   ',
        ]);

        $response->assertSessionHasErrors('clinical_content');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_only_examination_populated_succeeds(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'examination' => 'No visible decay.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DentalRecord::count());
    }

    public function test_patients_show_page_returns_this_patients_dental_records_newest_first_and_not_another_patients(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        DentalRecord::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);
        $older = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('dentalRecords', 2)
            ->where('dentalRecords.0.id', $newer->id)
            ->where('dentalRecords.1.id', $older->id)
        );
    }

    public function test_no_edit_or_delete_routes_exist_for_dental_records(): void
    {
        $this->assertFalse(Route::has('dental-records.update'));
        $this->assertFalse(Route::has('dental-records.destroy'));
    }
}
