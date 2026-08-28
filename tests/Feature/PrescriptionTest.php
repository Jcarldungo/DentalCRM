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
}
