<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $record = DentalRecord::create([
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
}
