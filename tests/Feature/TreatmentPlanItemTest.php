<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TreatmentPlanItemTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_treatment_plan_item_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'tooth_number' => 14,
            'treatment' => 'Root Canal Treatment',
            'estimated_cost' => 8000,
            'priority' => 'high',
            'status' => 'planned',
            'notes' => 'Patient reports sensitivity.',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $item->patient->id);
        $this->assertSame($provider->id, $item->provider->id);
        $this->assertSame($appointment->id, $item->appointment->id);
        $this->assertSame($user->id, $item->creator->id);
        // Unlike DentalRecord/ToothCondition, this row is mutable — it has
        // a real updated_at, not null.
        $this->assertNotNull($item->updated_at);
    }

    public function test_patient_treatment_plan_items_relation_orders_oldest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->treatmentPlanItems;

        $this->assertSame($older->id, $ordered->first()->id);
        $this->assertSame($newer->id, $ordered->last()->id);
    }

    public function test_patient_treatment_plan_items_relation_breaks_same_second_ties_by_id(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $sameInstant = now();
        $first = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);
        $second = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);

        $ordered = $patient->treatmentPlanItems;

        $this->assertSame($first->id, $ordered->first()->id);
        $this->assertSame($second->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_treatment_plan_items(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('treatment_plan_items', ['id' => $item->id]);
    }

    public function test_deleting_a_provider_nulls_the_treatment_plan_items_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($item->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_treatment_plan_items_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($item->fresh()->appointment_id);
    }
}
