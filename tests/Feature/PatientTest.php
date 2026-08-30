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
}
