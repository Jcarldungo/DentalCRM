<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_prescription_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $rx = Prescription::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $rx->patient->id);
        $this->assertSame($provider->id, $rx->provider->id);
        $this->assertSame($appointment->id, $rx->appointment->id);
        $this->assertSame($user->id, $rx->creator->id);
        $this->assertSame('active', $rx->status);
        $this->assertNotNull($rx->updated_at);
    }

    public function test_patient_prescriptions_relation_orders_newest_first_with_id_tiebreak(): void
    {
        $patient = Patient::factory()->create();
        $sameInstant = now();
        $first = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => $sameInstant]);
        $second = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => $sameInstant]);
        $older = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);

        $ordered = $patient->prescriptions;

        $this->assertSame($second->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_prescriptions(): void
    {
        $rx = Prescription::factory()->create();

        $rx->patient->delete();

        $this->assertDatabaseMissing('prescriptions', ['id' => $rx->id]);
    }

    public function test_deleting_a_provider_nulls_the_prescription_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $rx = Prescription::factory()->create(['provider_id' => $provider->id]);

        $provider->delete();

        $this->assertNull($rx->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_prescription_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $rx = Prescription::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ]);

        $appointment->delete();

        $this->assertNull($rx->fresh()->appointment_id);
    }

    public function test_discontinued_factory_state(): void
    {
        $rx = Prescription::factory()->discontinued()->create();

        $this->assertSame('discontinued', $rx->status);
        $this->assertNotNull($rx->discontinued_at);
    }

    public function test_guest_cannot_create_a_prescription(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, Prescription::count());
    }

    public function test_a_prescription_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'duration' => '7 days',
            'quantity' => '21 capsules',
            'instructions' => 'Take after meals. Finish the full course.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Prescription::count());
        $rx = Prescription::first();
        $this->assertSame($patient->id, $rx->patient_id);
        $this->assertSame('Amoxicillin', $rx->medication);
        $this->assertSame('500 mg', $rx->dosage);
        $this->assertSame('3 times daily', $rx->frequency);
        $this->assertSame('7 days', $rx->duration);
        $this->assertSame('21 capsules', $rx->quantity);
        $this->assertSame('Take after meals. Finish the full course.', $rx->instructions);
        $this->assertSame('active', $rx->status);
        $this->assertNull($rx->discontinued_at);
        $this->assertNull($rx->provider_id);
        $this->assertNull($rx->appointment_id);
        $this->assertSame($user->id, $rx->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Ibuprofen',
            'dosage' => '400 mg',
            'frequency' => 'Every 8 hours',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, Prescription::first()->created_by);
    }

    public function test_status_is_always_active_on_create_regardless_of_request(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Ibuprofen',
            'dosage' => '400 mg',
            'frequency' => 'Every 8 hours',
            'status' => 'discontinued',
        ]);

        $this->assertSame('active', Prescription::first()->status);
    }

    public function test_a_missing_required_field_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $complete = [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
        ];

        foreach (['medication', 'dosage', 'frequency'] as $missing) {
            $payload = $complete;
            unset($payload[$missing]);

            $response = $this->post(route('prescriptions.store', $patient), $payload);

            $response->assertSessionHasErrors($missing);
        }

        $this->assertSame(0, Prescription::count());
    }

    public function test_a_prescription_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Metronidazole',
            'dosage' => '400 mg',
            'frequency' => '3 times daily',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $rx = Prescription::first();
        $this->assertSame($provider->id, $rx->provider_id);
        $this->assertSame($appointment->id, $rx->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, Prescription::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, Prescription::count());
    }

    public function test_guest_cannot_discontinue_a_prescription(): void
    {
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Course completed',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame('active', $rx->fresh()->status);
    }

    public function test_discontinue_sets_status_timestamp_and_reason(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Patient reported a rash',
        ]);

        $response->assertRedirect();
        $rx->refresh();
        $this->assertSame('discontinued', $rx->status);
        $this->assertNotNull($rx->discontinued_at);
        $this->assertSame('Patient reported a rash', $rx->discontinued_reason);
    }

    public function test_discontinue_reason_is_optional(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), []);

        $response->assertRedirect();
        $rx->refresh();
        $this->assertSame('discontinued', $rx->status);
        $this->assertNull($rx->discontinued_reason);
    }

    public function test_discontinue_ignores_drug_fields_in_the_request_body(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create([
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
        ]);

        $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'medication' => 'HACKED',
            'dosage' => 'HACKED',
            'status' => 'active',
        ]);

        $rx->refresh();
        $this->assertSame('Amoxicillin', $rx->medication);
        $this->assertSame('500 mg', $rx->dosage);
        $this->assertSame('discontinued', $rx->status);
    }

    public function test_discontinue_is_one_way(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->discontinued()->create(['discontinued_reason' => 'Original reason']);

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Second attempt',
        ]);

        $response->assertForbidden();
        $this->assertSame('Original reason', $rx->fresh()->discontinued_reason);
    }

    public function test_discontinue_for_a_prescription_belonging_to_a_different_patient_404s(): void
    {
        $this->actingUser();
        $otherPatient = Patient::factory()->create();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $otherPatient->id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Course completed',
        ]);

        $response->assertNotFound();
        $this->assertSame('active', $rx->fresh()->status);
    }

    public function test_show_page_lists_the_patients_prescriptions_newest_first(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $older = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);
        $newer = Prescription::factory()->discontinued()->create(['patient_id' => $patient->id, 'created_at' => now()]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('prescriptions', 2)
            ->where('prescriptions.0.id', $newer->id)
            ->where('prescriptions.0.status', 'discontinued')
            ->where('prescriptions.1.id', $older->id)
            ->where('prescriptions.1.status', 'active')
        );
    }

    public function test_show_page_does_not_include_another_patients_prescriptions(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();
        Prescription::factory()->create(['patient_id' => $otherPatient->id]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('prescriptions', 0)
        );
    }

    public function test_show_page_prescription_shape(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create(['name' => 'Dr. Reyes']);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        Prescription::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'medication' => 'Amoxicillin',
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertInertia(fn ($page) => $page
            ->where('prescriptions.0.medication', 'Amoxicillin')
            ->where('prescriptions.0.provider_name', 'Dr. Reyes')
            ->where('prescriptions.0.creator_name', $user->name)
            ->whereNot('prescriptions.0.appointment_start_time', null)
        );
    }

    public function test_no_delete_route_exists_for_prescriptions(): void
    {
        $this->assertFalse(Route::has('prescriptions.destroy'));
    }
}
