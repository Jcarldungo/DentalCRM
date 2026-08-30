<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\ToothCondition;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_patient_with_no_history_can_be_deleted(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->delete(route('patients.destroy', $patient));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('patients', ['id' => $patient->id]);
    }

    /**
     * Each of these tables cascades on patients.id, and five of the six
     * hold records the app documents as append-only. One unguarded DELETE
     * used to take all of them.
     *
     * @return array<string, array{0: callable(Patient, User): array{0: string, 1: array<string, mixed>}}>
     */
    public static function historyProvider(): array
    {
        return [
            'appointments' => [fn (Patient $p, User $u) => [
                'appointments',
                Appointment::factory()->create(['patient_id' => $p->id])->only('id'),
            ]],
            'dental records' => [fn (Patient $p, User $u) => [
                'dental_records',
                DentalRecord::factory()->create(['patient_id' => $p->id, 'created_by' => $u->id])->only('id'),
            ]],
            'tooth conditions' => [fn (Patient $p, User $u) => [
                'tooth_conditions',
                ToothCondition::factory()->create(['patient_id' => $p->id, 'created_by' => $u->id])->only('id'),
            ]],
            'treatment plan items' => [fn (Patient $p, User $u) => [
                'treatment_plan_items',
                TreatmentPlanItem::factory()->create(['patient_id' => $p->id, 'created_by' => $u->id])->only('id'),
            ]],
            'prescriptions' => [fn (Patient $p, User $u) => [
                'prescriptions',
                Prescription::factory()->create(['patient_id' => $p->id, 'created_by' => $u->id])->only('id'),
            ]],
            'invoices' => [fn (Patient $p, User $u) => [
                'invoices',
                Invoice::factory()->create(['patient_id' => $p->id, 'created_by' => $u->id])->only('id'),
            ]],
        ];
    }

    #[DataProvider('historyProvider')]
    public function test_a_patient_with_history_cannot_be_deleted(callable $makeHistory): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        [$table, $childKey] = $makeHistory($patient, $user);

        $response = $this->delete(route('patients.destroy', $patient));

        $response->assertSessionHasErrors('patient');
        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
        $this->assertDatabaseHas($table, $childKey);
    }

    public function test_deleting_a_patient_with_billing_history_leaves_recorded_payments_intact(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id, 'created_by' => $user->id]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'created_by' => $user->id,
        ]);

        $this->delete(route('patients.destroy', $patient))->assertSessionHasErrors('patient');

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_guest_cannot_list_patients(): void
    {
        $response = $this->get(route('patients.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_the_patient_list_is_paginated(): void
    {
        $this->actingUser();
        Patient::factory()->count(30)->create();

        $this->get(route('patients.index'))->assertInertia(fn ($page) => $page
            ->component('Patients/Index')
            ->has('patients.data', 25)
            ->where('patients.total', 30)
            ->where('patients.last_page', 2)
        );

        $this->get(route('patients.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page->has('patients.data', 5));
    }

    public function test_search_matches_name_phone_and_email(): void
    {
        $this->actingUser();
        $target = Patient::factory()->create([
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'phone' => '09171234567',
            'email' => 'angela@example.com',
        ]);
        Patient::factory()->create(['first_name' => 'Rico', 'last_name' => 'Santos']);

        foreach (['Angela', 'reyes', 'Angela Reyes', '0917123', 'angela@'] as $term) {
            $this->get(route('patients.index', ['search' => $term]))
                ->assertInertia(fn ($page) => $page
                    ->has('patients.data', 1)
                    ->where('patients.data.0.id', $target->id)
                    ->where('filters.search', $term)
                );
        }
    }

    public function test_search_wildcards_are_escaped(): void
    {
        $this->actingUser();
        Patient::factory()->create(['first_name' => 'Angela', 'last_name' => 'Reyes']);

        // A bare % would otherwise match everything.
        $this->get(route('patients.index', ['search' => '%']))
            ->assertInertia(fn ($page) => $page->has('patients.data', 0));
    }

    /**
     * The three things a receptionist looks a patient up for. They are
     * grouped aggregates over the page of results, not a query per row.
     */
    public function test_each_row_carries_last_visit_next_visit_and_balance(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'completed',
            'start_time' => now()->subDays(10),
            'end_time' => now()->subDays(10)->addHour(),
        ]);
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'scheduled',
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(5)->addHour(),
        ]);

        $invoice = Invoice::factory()->issued()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1500]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'created_by' => $user->id,
        ]);

        $this->get(route('patients.index'))->assertInertia(function ($page) use ($patient) {
            $summary = $page->toArray()['props']['summaries'][$patient->id];

            $this->assertNotNull($summary['last_visit']);
            $this->assertNotNull($summary['next_visit']);
            $this->assertSame(1000, $summary['balance']);
        });
    }

    public function test_a_duplicate_email_is_a_form_error_not_a_database_failure(): void
    {
        $this->actingUser();
        Patient::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('patients.store'), [
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => 'taken@example.com',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_patient_can_keep_their_own_email_when_updated(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create(['email' => 'angela@example.com']);

        $this->put(route('patients.update', $patient), [
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => 'angela@example.com',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        $this->actingUser();

        $this->post(route('patients.store'), [
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'date_of_birth' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('date_of_birth');
    }

    public function test_saving_a_patient_flashes_a_confirmation(): void
    {
        $this->actingUser();

        $this->post(route('patients.store'), ['first_name' => 'Angela', 'last_name' => 'Reyes'])
            ->assertSessionHas('success');
    }
}
