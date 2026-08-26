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

    public function test_patient_tooth_conditions_relation_breaks_same_second_ties_by_id(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $sameInstant = now();
        $first = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);
        $second = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);

        $ordered = $patient->toothConditions;

        $this->assertSame($second->id, $ordered->first()->id);
        $this->assertSame($first->id, $ordered->last()->id);
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

    public function test_guest_cannot_create_a_tooth_condition(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_tooth_condition_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'filling',
            'notes' => 'Composite filling placed.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, ToothCondition::count());
        $condition = ToothCondition::first();
        $this->assertSame($patient->id, $condition->patient_id);
        $this->assertSame(14, $condition->tooth_number);
        $this->assertSame('filling', $condition->condition);
        $this->assertSame('Composite filling placed.', $condition->notes);
        $this->assertNull($condition->provider_id);
        $this->assertNull($condition->appointment_id);
        $this->assertSame($user->id, $condition->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, ToothCondition::first()->created_by);
    }

    public function test_a_tooth_condition_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 3,
            'condition' => 'extraction',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $condition = ToothCondition::first();
        $this->assertSame($provider->id, $condition->provider_id);
        $this->assertSame($appointment->id, $condition->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_an_invalid_condition_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'not-a-real-condition',
        ]);

        $response->assertSessionHasErrors('condition');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_missing_condition_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
        ]);

        $response->assertSessionHasErrors('condition');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_tooth_number_outside_1_to_32_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $tooHigh = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 33,
            'condition' => 'healthy',
        ]);
        $tooHigh->assertSessionHasErrors('tooth_number');

        $tooLow = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 0,
            'condition' => 'healthy',
        ]);
        $tooLow->assertSessionHasErrors('tooth_number');

        $this->assertSame(0, ToothCondition::count());
    }

    public function test_current_state_for_a_tooth_is_derivable_as_the_newest_entry(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $older = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'tooth_number' => 14,
            'condition' => 'caries',
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'tooth_number' => 14,
            'condition' => 'filling',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('toothConditions', 2)
            ->where('toothConditions.0.id', $newer->id)
            ->where('toothConditions.0.condition', 'filling')
            ->where('toothConditions.1.id', $older->id)
        );
    }

    public function test_patients_show_page_does_not_include_another_patients_tooth_conditions(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        ToothCondition::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('toothConditions', 0)
        );
    }

    public function test_no_edit_or_delete_routes_exist_for_tooth_conditions(): void
    {
        $this->assertFalse(Route::has('tooth-conditions.update'));
        $this->assertFalse(Route::has('tooth-conditions.destroy'));
    }
}
