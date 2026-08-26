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

    public function test_guest_cannot_create_a_treatment_plan_item(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Root Canal Treatment',
            'estimated_cost' => 8000,
            'priority' => 'high',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_update_a_treatment_plan_item(): void
    {
        $item = TreatmentPlanItem::factory()->create();

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_treatment_plan_item_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Root Canal Treatment',
            'tooth_number' => 14,
            'estimated_cost' => 8000,
            'priority' => 'high',
            'notes' => 'Patient reports sensitivity.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, TreatmentPlanItem::count());
        $item = TreatmentPlanItem::first();
        $this->assertSame($patient->id, $item->patient_id);
        $this->assertSame('Root Canal Treatment', $item->treatment);
        $this->assertSame(14, $item->tooth_number);
        $this->assertSame('8000.00', $item->estimated_cost);
        $this->assertSame('high', $item->priority);
        $this->assertSame('planned', $item->status);
        $this->assertSame('Patient reports sensitivity.', $item->notes);
        $this->assertNull($item->provider_id);
        $this->assertNull($item->appointment_id);
        $this->assertSame($user->id, $item->created_by);
    }

    public function test_new_items_always_start_planned_regardless_of_request_status(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Teeth Whitening',
            'estimated_cost' => 3000,
            'priority' => 'low',
            'status' => 'completed',
        ]);

        $this->assertSame('planned', TreatmentPlanItem::first()->status);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, TreatmentPlanItem::first()->created_by);
    }

    public function test_a_treatment_plan_item_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Crown',
            'estimated_cost' => 15000,
            'priority' => 'medium',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $item = TreatmentPlanItem::first();
        $this->assertSame($provider->id, $item->provider_id);
        $this->assertSame($appointment->id, $item->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'urgent',
        ]);

        $response->assertSessionHasErrors('priority');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_missing_treatment_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'estimated_cost' => 1500,
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('treatment');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_missing_estimated_cost_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('estimated_cost');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_negative_estimated_cost_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => -100,
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('estimated_cost');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_tooth_number_outside_1_to_32_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $tooHigh = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'tooth_number' => 33,
        ]);
        $tooHigh->assertSessionHasErrors('tooth_number');

        $tooLow = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'tooth_number' => 0,
        ]);
        $tooLow->assertSessionHasErrors('tooth_number');

        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_update_changes_status_priority_cost_and_notes(): void
    {
        $this->actingUser();
        $item = TreatmentPlanItem::factory()->create([
            'status' => 'planned',
            'priority' => 'low',
            'estimated_cost' => 1000,
            'notes' => null,
        ]);

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'in_progress',
            'priority' => 'high',
            'estimated_cost' => 2500,
            'notes' => 'Started today.',
        ]);

        $response->assertRedirect();
        $item->refresh();
        $this->assertSame('in_progress', $item->status);
        $this->assertSame('high', $item->priority);
        $this->assertSame('2500.00', $item->estimated_cost);
        $this->assertSame('Started today.', $item->notes);
    }

    public function test_update_ignores_treatment_tooth_number_provider_and_appointment_changes(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'treatment' => 'Original Treatment',
            'tooth_number' => 10,
            'provider_id' => null,
            'appointment_id' => null,
        ]);
        $otherAppointment = Appointment::factory()->create(['patient_id' => $item->patient_id]);

        $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'scheduled',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
            'treatment' => 'Changed Treatment',
            'tooth_number' => 20,
            'provider_id' => $provider->id,
            'appointment_id' => $otherAppointment->id,
        ]);

        $item->refresh();
        $this->assertSame('Original Treatment', $item->treatment);
        $this->assertSame(10, $item->tooth_number);
        $this->assertNull($item->provider_id);
        $this->assertNull($item->appointment_id);
        $this->assertSame('scheduled', $item->status);
    }

    public function test_update_for_an_item_belonging_to_a_different_patient_404s(): void
    {
        $this->actingUser();
        $otherPatient = Patient::factory()->create();
        $item = TreatmentPlanItem::factory()->create();

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $otherPatient->id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response->assertNotFound();
        $this->assertSame('planned', $item->fresh()->status);
    }

    public function test_show_page_lists_treatment_plan_items_and_reflects_updates(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'planned',
        ]);

        $this->patch(route('treatment-plan-items.update', ['patient' => $patient->id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('treatmentPlanItems', 1)
            ->where('treatmentPlanItems.0.id', $item->id)
            ->where('treatmentPlanItems.0.status', 'completed')
        );
    }

    public function test_patients_show_page_does_not_include_another_patients_treatment_plan_items(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        TreatmentPlanItem::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('treatmentPlanItems', 0)
        );
    }

    public function test_no_delete_route_exists_for_treatment_plan_items(): void
    {
        $this->assertFalse(Route::has('treatment-plan-items.destroy'));
    }
}
