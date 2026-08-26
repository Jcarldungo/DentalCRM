<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ToothCondition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ToothConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_tooth_condition_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $condition = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'tooth_number' => 14,
            'condition' => 'filling',
            'notes' => 'Composite filling placed.',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $condition->patient->id);
        $this->assertSame($provider->id, $condition->provider->id);
        $this->assertSame($appointment->id, $condition->appointment->id);
        $this->assertSame($user->id, $condition->creator->id);
        $this->assertNull($condition->updated_at);
    }

    public function test_patient_tooth_conditions_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->toothConditions;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_tooth_conditions(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('tooth_conditions', ['id' => $condition->id]);
    }

    public function test_deleting_a_provider_nulls_the_tooth_conditions_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($condition->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_tooth_conditions_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($condition->fresh()->appointment_id);
    }
}
