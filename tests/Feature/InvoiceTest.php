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
}
