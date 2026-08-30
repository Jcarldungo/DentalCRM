<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_a_voided_invoice_cannot_be_issued(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->void()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);

        $this->patch(route('invoices.update', $invoice), ['status' => 'issued'])
            ->assertSessionHasErrors('status');

        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->voided_at);
    }

    public function test_a_draft_with_no_line_items_cannot_be_issued(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create();

        $this->patch(route('invoices.update', $invoice), ['status' => 'issued'])
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $invoice->fresh()->status);
    }

    /**
     * The legality of a transition has to be decided against the row read
     * under the lock, not the pre-lock snapshot — otherwise a concurrent
     * {void} + {issued} pair both pass their checks and one resurrects the
     * other. A single test connection can't interleave transactions, so
     * assert the shape: nothing decides anything before the lock is taken.
     */
    public function test_transition_legality_is_decided_under_the_lock(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/InvoiceController.php'));
        $afterSignature = last(explode('protected function transition(', $source));
        // Stop at the next method so later methods' use of $invoice
        // (present(), show()) doesn't leak into the assertions.
        $body = preg_split('/\n    (public|protected|private) function /', $afterSignature)[0];

        $this->assertStringContainsString('$from = $locked->status;', $body);
        $this->assertStringContainsString('$locked->items()->count()', $body);
        $this->assertStringContainsString('$locked->payments()->count()', $body);
        $this->assertStringNotContainsString('$invoice->status', $body);
        $this->assertStringNotContainsString('$invoice->items()', $body);
    }

    public function test_a_null_status_in_an_edit_payload_does_not_route_into_transition(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->patch(route('invoices.update', $invoice), [
            'status' => null,
            'items' => [['description' => 'Consultation', 'amount' => 750]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('Consultation', $invoice->fresh()->items->first()->description);
    }

    public function test_invoice_number_is_derived_and_zero_padded(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT), $invoice->number());
    }

    public function test_money_helpers_derive_subtotal_total_paid_and_balance(): void
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 200]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $invoice->load(['items', 'payments']);

        $this->assertSame(1500.0, $invoice->subtotal());
        $this->assertSame(1300.0, $invoice->total());
        $this->assertSame(300.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->balance());
        $this->assertFalse($invoice->isPaid());
    }

    public function test_is_paid_is_true_only_when_issued_and_balance_zero(): void
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        $invoice->load(['items', 'payments']);
        $this->assertTrue($invoice->isPaid());

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 0]);
        $draft->load(['items', 'payments']);
        $this->assertFalse($draft->isPaid());
    }

    public function test_deleting_a_patient_cascades_to_invoices_items_and_payments(): void
    {
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id]);
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id]);

        $patient->delete();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_a_provider_nulls_an_invoice_items_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $item = InvoiceItem::factory()->create(['provider_id' => $provider->id]);

        $provider->delete();

        $this->assertNull($item->fresh()->provider_id);
    }

    public function test_patient_invoices_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $older = Invoice::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);
        $newer = Invoice::factory()->create(['patient_id' => $patient->id, 'created_at' => now()]);

        $ordered = $patient->invoices;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_treatment_plan_item_link_is_nullable_and_belongs_to(): void
    {
        $tpi = TreatmentPlanItem::factory()->create();
        $item = InvoiceItem::factory()->create(['treatment_plan_item_id' => $tpi->id]);

        $this->assertSame($tpi->id, $item->treatmentPlanItem->id);
    }

    public function test_no_invoice_destroy_or_payment_write_routes_exist(): void
    {
        $this->assertFalse(Route::has('invoices.destroy'));
        $this->assertFalse(Route::has('invoice-payments.update'));
        $this->assertFalse(Route::has('invoice-payments.destroy'));
    }

    public function test_guest_cannot_create_or_view_invoices(): void
    {
        $invoice = Invoice::factory()->create();

        $this->post(route('invoices.store'), [])->assertRedirect(route('login'));
        $this->get(route('invoices.show', $invoice))->assertRedirect(route('login'));
    }

    public function test_it_creates_a_draft_invoice_with_line_items(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'discount_amount' => 100,
            'notes' => 'First visit.',
            'items' => [
                ['description' => 'Consultation fee', 'amount' => 800],
                ['description' => 'X-ray', 'amount' => 1200],
            ],
        ]);

        $invoice = Invoice::first();
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($patient->id, $invoice->patient_id);
        $this->assertSame('100.00', $invoice->discount_amount);
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame(2, $invoice->items()->count());
        $this->assertNull($invoice->issued_at);
    }

    public function test_created_by_ignores_a_request_supplied_value(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'created_by' => $other->id,
            'status' => 'issued',
            'items' => [['description' => 'Cleaning', 'amount' => 1500]],
        ]);

        $invoice = Invoice::first();
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame('draft', $invoice->status);
    }

    public function test_a_line_can_link_to_a_treatment_plan_item_and_copies_its_provider(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $tpi = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
        ]);

        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [
                ['description' => 'Root canal', 'amount' => 8000, 'treatment_plan_item_id' => $tpi->id],
            ],
        ]);

        $item = InvoiceItem::first();
        $this->assertSame($tpi->id, $item->treatment_plan_item_id);
        $this->assertSame($provider->id, $item->provider_id);
    }

    public function test_store_validation_rejects_bad_payloads(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsTpi = TreatmentPlanItem::factory()->create();

        $this->post(route('invoices.store'), ['patient_id' => $patient->id, 'items' => []])
            ->assertSessionHasErrors('items');
        $this->post(route('invoices.store'), ['patient_id' => $patient->id])
            ->assertSessionHasErrors('items');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['amount' => 100]],
        ])->assertSessionHasErrors('items.0.description');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['description' => 'x', 'amount' => -5]],
        ])->assertSessionHasErrors('items.0.amount');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['description' => 'x', 'amount' => 100, 'treatment_plan_item_id' => $otherPatientsTpi->id]],
        ])->assertSessionHasErrors('items.0.treatment_plan_item_id');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'discount_amount' => 500,
            'items' => [['description' => 'x', 'amount' => 100]],
        ])->assertSessionHasErrors('discount_amount');

        $this->assertSame(0, Invoice::count());
    }

    public function test_show_returns_the_invoice_with_derived_figures(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 100]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 400]);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Invoices/Show')
            ->where('invoice.number', $invoice->number())
            ->where('invoice.subtotal', 1000)
            ->where('invoice.total', 900)
            ->where('invoice.amount_paid', 400)
            ->where('invoice.balance', 500)
            ->where('invoice.is_paid', false)
            ->has('invoice.items', 1)
            ->has('invoice.payments', 1)
        );
    }

    public function test_show_exposes_linkable_treatment_plan_items_for_the_patient(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $open = TreatmentPlanItem::factory()->create(['patient_id' => $patient->id, 'status' => 'planned']);
        TreatmentPlanItem::factory()->create(['patient_id' => $patient->id, 'status' => 'cancelled']);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertInertia(fn ($page) => $page
            ->has('treatmentPlanItems', 1)
            ->where('treatmentPlanItems.0.id', $open->id)
        );
    }

    public function test_guest_cannot_update_an_invoice(): void
    {
        $invoice = Invoice::factory()->create();

        $this->patch(route('invoices.update', $invoice), ['status' => 'issued'])
            ->assertRedirect(route('login'));
    }

    public function test_editing_a_draft_replaces_items_and_updates_discount_and_notes(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create(['discount_amount' => 0, 'notes' => null]);
        $stale = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->patch(route('invoices.update', $invoice), [
            'discount_amount' => 50,
            'notes' => 'Adjusted.',
            'items' => [
                ['description' => 'New line A', 'amount' => 400],
                ['description' => 'New line B', 'amount' => 600],
            ],
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('50.00', $invoice->discount_amount);
        $this->assertSame('Adjusted.', $invoice->notes);
        $this->assertDatabaseMissing('invoice_items', ['id' => $stale->id]);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_editing_is_rejected_once_issued_or_void(): void
    {
        $this->actingUser();
        $issued = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $issued->id, 'amount' => 100]);

        $this->patch(route('invoices.update', $issued), [
            'items' => [['description' => 'Sneaky', 'amount' => 999]],
        ])->assertForbidden();

        $this->assertSame(1, $issued->items()->count());
        $this->assertSame('100.00', $issued->items()->first()->amount);

        $void = Invoice::factory()->void()->create();
        $stale = InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 200]);

        $this->patch(route('invoices.update', $void), [
            'items' => [['description' => 'Sneaky', 'amount' => 999]],
        ])->assertForbidden();

        $this->assertSame(1, $void->items()->count());
        $this->assertSame($stale->id, $void->items()->first()->id);
    }

    public function test_patch_with_status_and_edit_fields_runs_the_transition_and_ignores_edits(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create(['discount_amount' => 0]);
        $original = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);

        $this->patch(route('invoices.update', $invoice), [
            'status' => 'issued',
            'items' => [['description' => 'Different line', 'amount' => 4200]],
            'discount_amount' => 999,
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($original->id, $invoice->items()->first()->id);
        $this->assertSame('0.00', $invoice->discount_amount);
    }

    public function test_edit_mode_rejects_a_discount_over_the_new_subtotal(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create(['discount_amount' => 0]);
        $original = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $this->patch(route('invoices.update', $invoice), [
            'items' => [['description' => 'New line', 'amount' => 100]],
            'discount_amount' => 200,
        ])->assertSessionHasErrors('discount_amount');

        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($original->id, $invoice->items()->first()->id);
    }

    public function test_edit_mode_rejects_a_cross_patient_treatment_plan_item(): void
    {
        $this->actingUser();
        $patientA = Patient::factory()->create();
        $patientB = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patientA->id]);
        $foreignTpi = TreatmentPlanItem::factory()->create(['patient_id' => $patientB->id]);

        $this->patch(route('invoices.update', $invoice), [
            'items' => [
                ['description' => 'New line', 'amount' => 100, 'treatment_plan_item_id' => $foreignTpi->id],
            ],
        ])->assertSessionHasErrors('items.0.treatment_plan_item_id');
    }

    public function test_issuing_requires_at_least_one_line_item(): void
    {
        $this->actingUser();
        $empty = Invoice::factory()->create();

        $this->patch(route('invoices.update', $empty), ['status' => 'issued'])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $empty->fresh()->status);

        $ok = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $ok->id, 'amount' => 500]);
        $this->patch(route('invoices.update', $ok), ['status' => 'issued'])->assertRedirect();
        $ok->refresh();
        $this->assertSame('issued', $ok->status);
        $this->assertNotNull($ok->issued_at);
    }

    public function test_a_draft_voids_freely_and_stamps_voided_at(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create();

        $this->patch(route('invoices.update', $invoice), ['status' => 'void'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('void', $invoice->status);
        $this->assertNotNull($invoice->voided_at);
    }

    public function test_an_issued_invoice_voids_only_without_payments(): void
    {
        $this->actingUser();
        $withPayment = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $withPayment->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $withPayment->id, 'amount' => 100]);

        $this->patch(route('invoices.update', $withPayment), ['status' => 'void'])
            ->assertSessionHasErrors('status');
        $this->assertSame('issued', $withPayment->fresh()->status);

        $clean = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $clean->id, 'amount' => 1000]);
        $this->patch(route('invoices.update', $clean), ['status' => 'void'])->assertRedirect();
        $this->assertSame('void', $clean->fresh()->status);
    }

    public function test_illegal_transitions_are_rejected(): void
    {
        $this->actingUser();

        $issued = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $issued->id, 'amount' => 100]);
        $this->patch(route('invoices.update', $issued), ['status' => 'draft'])
            ->assertSessionHasErrors('status');
        $this->assertSame('issued', $issued->fresh()->status);

        $void = Invoice::factory()->void()->create();
        $this->patch(route('invoices.update', $void), ['status' => 'issued'])
            ->assertSessionHasErrors('status');
        $this->patch(route('invoices.update', $void), ['status' => 'draft'])
            ->assertSessionHasErrors('status');
        $this->assertSame('void', $void->fresh()->status);
    }

    public function test_guest_cannot_view_the_invoices_index(): void
    {
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_all_invoices_newest_first_by_default(): void
    {
        $this->actingUser();
        $older = Invoice::factory()->create(['created_at' => now()->subDay()]);
        $newer = Invoice::factory()->create(['created_at' => now()]);

        $response = $this->get(route('invoices.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Invoices/Index')
            ->where('filters.status', 'all')
            ->has('invoices', 2)
            ->where('invoices.0.id', $newer->id)
            ->where('invoices.1.id', $older->id)
        );
    }

    public function test_index_status_filters_bucket_correctly(): void
    {
        $this->actingUser();

        $draft = Invoice::factory()->create();

        $outstanding = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 400]);

        $paid = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);

        $void = Invoice::factory()->void()->create();

        $this->get(route('invoices.index', ['status' => 'draft']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $draft->id));
        $this->get(route('invoices.index', ['status' => 'outstanding']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $outstanding->id));
        $this->get(route('invoices.index', ['status' => 'paid']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $paid->id));
        $this->get(route('invoices.index', ['status' => 'void']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $void->id));
        $this->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->has('invoices', 4));
    }

    public function test_index_rejects_an_unknown_status_filter(): void
    {
        $this->actingUser();

        $this->get(route('invoices.index', ['status' => 'nonsense']))
            ->assertSessionHasErrors('status');
    }

    public function test_patient_show_lists_only_that_patients_invoices_with_derived_figures(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();

        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id, 'discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250]);
        Invoice::factory()->create(['patient_id' => $other->id]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('invoices', 1)
            ->where('invoices.0.id', $invoice->id)
            ->where('invoices.0.number', $invoice->number())
            ->where('invoices.0.total', 1000)
            ->where('invoices.0.amount_paid', 250)
            ->where('invoices.0.balance', 750)
            ->where('invoices.0.is_paid', false)
        );
    }

    public function test_dashboard_outstanding_totals_only_issued_invoices_with_a_balance(): void
    {
        $this->actingUser();

        $outstanding = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 200]);

        $paid = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 9999]);

        $void = Invoice::factory()->void()->create();
        InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 9999]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('outstanding.total', 800)
            ->where('outstanding.count', 1)
        );
    }
}
