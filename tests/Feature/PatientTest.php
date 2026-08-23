<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_patient_can_be_created(): void
    {
        $this->actingUser();

        $response = $this->post(route('patients.store'), [
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'date_of_birth' => '1990-05-14',
            'phone' => '09171234567',
            'email' => 'maria@example.com',
            'emergency_contact_name' => 'Juan Cruz',
            'emergency_contact_phone' => '09179876543',
            'notes' => 'Allergic to latex.',
            'recall_interval_months' => 6,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('patients', [
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'email' => 'maria@example.com',
        ]);
    }

    public function test_first_and_last_name_are_required(): void
    {
        $this->actingUser();

        $response = $this->post(route('patients.store'), ['phone' => '09171234567']);

        $response->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_full_name_accessor_joins_first_and_last_name(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Cruz']);

        $this->assertSame('Maria Cruz', $patient->full_name);
    }

    public function test_patient_can_be_updated(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->put(route('patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'phone' => '09170000000',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'phone' => '09170000000']);
    }

    public function test_updating_patient_does_not_null_date_of_birth(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create(['date_of_birth' => '1990-05-14']);

        $response = $this->put(route('patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'phone' => '09170000001',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'phone' => '09170000001',
            'date_of_birth' => '1990-05-14',
        ]);
    }

    public function test_patient_can_be_deleted(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->delete(route('patients.destroy', $patient));

        $response->assertRedirect();
        $this->assertDatabaseMissing('patients', ['id' => $patient->id]);
    }

    public function test_guest_cannot_list_patients(): void
    {
        $response = $this->get(route('patients.index'));

        $response->assertRedirect(route('login'));
    }
}
