<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Several Phase 8 findings were only preventable, never detectable —
 * nothing recorded that a patient delete was attempted, that an invoice
 * was voided, or that a payment was taken. These cover the detection half.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(string $name = 'Front Desk'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->actingAs($user);

        return $user;
    }

    private function issuedInvoice(float $amount = 1000): Invoice
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);

        return $invoice;
    }

    public function test_a_refused_patient_delete_is_recorded(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->delete(route('patients.destroy', $patient))->assertSessionHasErrors('patient');

        $entry = AuditLog::latest('id')->first();
        $this->assertSame('patient.delete_refused', $entry->action);
        $this->assertSame($patient->id, $entry->subject_id);
        $this->assertSame('Patient', $entry->subject_type);
    }

    public function test_a_completed_patient_delete_is_recorded(): void
    {
        $this->actingUser('Angela Reyes');
        $patient = Patient::factory()->create(['first_name' => 'Rico', 'last_name' => 'Santos']);

        $this->delete(route('patients.destroy', $patient))->assertSessionHasNoErrors();

        $entry = AuditLog::latest('id')->first();
        $this->assertSame('patient.deleted', $entry->action);
        $this->assertSame('Rico Santos', $entry->subject_label);
        $this->assertSame('Angela Reyes', $entry->actor_name);
    }

    public function test_an_appointment_status_change_records_both_ends(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 45),
        ]);

        $this->patch(route('appointments.update', $appointment), ['status' => 'checked_in']);

        $entry = AuditLog::where('action', 'appointment.status_changed')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('scheduled', $entry->context['from']);
        $this->assertSame('checked_in', $entry->context['to']);
    }

    public function test_an_edit_that_does_not_change_status_records_nothing(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 45),
        ]);

        $this->patch(route('appointments.update', $appointment), ['type' => 'cleaning']);

        $this->assertSame(0, AuditLog::where('action', 'appointment.status_changed')->count());
    }

    public function test_issuing_and_voiding_an_invoice_are_recorded(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);

        $this->patch(route('invoices.update', $invoice), ['status' => 'issued']);
        $this->assertSame('invoice.issued', AuditLog::latest('id')->first()->action);

        $this->patch(route('invoices.update', $invoice), ['status' => 'void']);
        $entry = AuditLog::latest('id')->first();
        $this->assertSame('invoice.voided', $entry->action);
        $this->assertSame($invoice->number(), $entry->subject_label);
    }

    public function test_a_payment_records_its_amount_and_method(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 250, 'method' => 'card']);

        $entry = AuditLog::where('action', 'payment.recorded')->latest('id')->first();
        // A whole-number float round-trips through the JSON column as an
        // int, so compare the value rather than the type.
        $this->assertEquals(250, $entry->context['amount']);
        $this->assertSame('card', $entry->context['method']);
    }

    public function test_discontinuing_a_prescription_is_recorded(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $prescription = Prescription::factory()->create([
            'patient_id' => $patient->id,
            'medication' => 'Amoxicillin',
            'created_by' => $user->id,
        ]);

        $this->patch(route('prescriptions.update', ['patient' => $patient->id, 'prescription' => $prescription->id]), []);

        $entry = AuditLog::latest('id')->first();
        $this->assertSame('prescription.discontinued', $entry->action);
        $this->assertSame('Amoxicillin', $entry->subject_label);
    }

    public function test_archiving_and_restoring_an_item_are_recorded_but_an_ordinary_edit_is_not(): void
    {
        $user = $this->actingUser();
        $item = InventoryItem::factory()->create(['created_by' => $user->id, 'name' => 'Cotton Rolls']);

        $this->patch(route('inventory.update', $item), ['active' => false]);
        $this->assertSame('inventory.archived', AuditLog::latest('id')->first()->action);

        $this->patch(route('inventory.update', $item), ['active' => true]);
        $this->assertSame('inventory.restored', AuditLog::latest('id')->first()->action);

        $before = AuditLog::count();
        $this->patch(route('inventory.update', $item), ['name' => 'Cotton Rolls, large']);
        $this->assertSame($before, AuditLog::count(), 'a routine edit should not fill the log');
    }

    public function test_a_refused_provider_delete_is_recorded(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create(['provider_id' => $provider->id]);

        $this->delete(route('providers.destroy', $provider))->assertSessionHasErrors('provider');

        $this->assertSame('provider.delete_refused', AuditLog::latest('id')->first()->action);
    }

    /**
     * The log has to outlive the accounts it describes. user_id nulls out
     * when a staff account goes, but the entry and the name it recorded
     * must not.
     */
    public function test_an_entry_survives_the_deletion_of_its_actor(): void
    {
        $user = $this->actingUser('Departing Staffer');
        $patient = Patient::factory()->create();
        $this->delete(route('patients.destroy', $patient));

        $entry = AuditLog::latest('id')->first();
        $this->assertSame($user->id, $entry->user_id);

        $this->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertNull($entry->user_id);
        $this->assertSame('Departing Staffer', $entry->actor_name);
    }

    public function test_the_log_is_append_only(): void
    {
        $this->assertFalse(Route::has('activity.store'));
        $this->assertFalse(Route::has('activity.update'));
        $this->assertFalse(Route::has('activity.destroy'));
        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertFalse(Schema::hasColumn('audit_log', 'updated_at'));
    }

    public function test_the_activity_page_lists_entries_newest_first(): void
    {
        $this->actingUser();
        AuditLog::record('patient.deleted', null, 'Older');
        AuditLog::record('invoice.issued', null, 'Newer');

        $this->get(route('activity.index'))->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 2)
            ->where('entries.data.0.subject_label', 'Newer')
            ->where('entries.data.1.subject_label', 'Older')
        );
    }

    public function test_the_activity_page_filters_by_action(): void
    {
        $this->actingUser();
        AuditLog::record('patient.deleted', null, 'A patient');
        AuditLog::record('invoice.issued', null, 'INV-000001');

        $this->get(route('activity.index', ['action' => 'invoice.issued']))
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.subject_label', 'INV-000001')
            );

        // The filter only offers actions that have actually happened.
        $this->get(route('activity.index'))->assertInertia(fn ($page) => $page->has('actions', 2));
    }

    public function test_an_unknown_action_filter_is_rejected(): void
    {
        $this->actingUser();

        $this->get(route('activity.index', ['action' => 'nonsense']))->assertSessionHasErrors('action');
    }

    public function test_a_guest_cannot_read_the_activity_log(): void
    {
        $this->get(route('activity.index'))->assertRedirect(route('login'));
    }

    /**
     * Nothing recorded may be free clinical text or contact detail — the
     * log must not become a second, less-guarded copy of the record.
     */
    public function test_recorded_context_carries_no_free_text(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create(['notes' => 'Allergic to latex.']);
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'note' => 'Paid in person by the patient, receipt 123',
            'reference' => 'RCPT-123',
        ]);

        $context = AuditLog::where('action', 'payment.recorded')->latest('id')->first()->context;

        $this->assertSame(['amount', 'method'], array_keys($context));
        $this->assertStringNotContainsString('receipt', json_encode($context));
        $this->assertStringNotContainsString($patient->notes, json_encode(AuditLog::pluck('context')->all()));
    }
}
