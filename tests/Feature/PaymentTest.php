<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** An issued invoice with a single $amount line item and no payments. */
    protected function issuedInvoice(float $amount = 1000): Invoice
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);

        return $invoice;
    }

    public function test_guest_cannot_record_a_payment(): void
    {
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'cash'])
            ->assertRedirect(route('login'));
    }

    public function test_it_records_a_payment_against_an_issued_invoice(): void
    {
        $user = $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 400,
            'method' => 'card',
            'reference' => 'AUTH123',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('400.00', $payment->amount);
        $this->assertSame('card', $payment->method);
        $this->assertSame('AUTH123', $payment->reference);
        $this->assertSame(now()->toDateString(), $payment->paid_on->toDateString());
        $this->assertSame($user->id, $payment->created_by);
    }

    public function test_paid_on_is_respected_when_supplied(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        // Relative to the invoice, not a hardcoded date: paid_on is now
        // floored at the issue date, and a fixed date would also be a time
        // bomb the moment the clock passes it.
        $paidOn = $invoice->issued_at->toDateString();

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'paid_on' => $paidOn,
        ])->assertSessionHasNoErrors();

        $this->assertSame($paidOn, Payment::first()->paid_on->toDateString());
    }

    public function test_payments_are_only_allowed_on_issued_invoices(): void
    {
        $this->actingUser();

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 500]);
        $this->post(route('invoice-payments.store', $draft), ['amount' => 100, 'method' => 'cash'])
            ->assertForbidden();

        $void = Invoice::factory()->void()->create();
        $this->post(route('invoice-payments.store', $void), ['amount' => 100, 'method' => 'cash'])
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_amount_must_be_positive_and_within_the_balance(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 0, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoice-payments.store', $invoice), ['amount' => -50, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoice-payments.store', $invoice), ['amount' => 1000.01, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_equal_to_the_balance_is_accepted_and_closes_the_invoice(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 1000, 'method' => 'cash'])
            ->assertRedirect();

        $invoice->load(['items', 'payments']);
        $this->assertSame(0.0, $invoice->balance());
        $this->assertTrue($invoice->isPaid());
    }

    public function test_partial_payments_accumulate(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 300, 'method' => 'cash']);
        $this->post(route('invoice-payments.store', $invoice), ['amount' => 400, 'method' => 'cash']);

        $invoice->load(['items', 'payments']);
        $this->assertSame(300.0, $invoice->balance());
        $this->assertFalse($invoice->isPaid());

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 300, 'method' => 'cash']);
        $invoice->load(['items', 'payments']);
        $this->assertTrue($invoice->fresh()->load(['items', 'payments'])->isPaid());
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'bitcoin'])
            ->assertSessionHasErrors('method');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_against_a_voided_invoice_is_refused(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['status' => 'void', 'voided_at' => now()])->save();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'cash'])
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    /**
     * The pre-lock abort_unless is a cheap early exit; the check that holds
     * under a concurrent void is the one inside the transaction. A single
     * test connection can't actually interleave two transactions, so assert
     * the shape instead: the status check appears both before and inside
     * the DB::transaction closure.
     */
    public function test_invoice_status_is_checked_again_inside_the_transaction(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/PaymentController.php'));
        [, $insideTransaction] = explode('DB::transaction(', $source, 2);

        $this->assertStringContainsString(
            "abort_unless(\$locked->status === 'issued', 403)",
            $insideTransaction,
            'The locked invoice\'s status must be re-checked inside the transaction.',
        );
    }

    public function test_a_future_paid_on_date_is_rejected(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'paid_on' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, Payment::count());
    }

    public function test_payment_controller_has_no_write_methods_beyond_store(): void
    {
        $this->assertFalse(method_exists(\App\Http\Controllers\Admin\PaymentController::class, 'update'));
        $this->assertFalse(method_exists(\App\Http\Controllers\Admin\PaymentController::class, 'destroy'));
    }

    /**
     * Bounded at both ends. The floor is the invoice's own issue date:
     * money cannot have been received against a bill that did not exist
     * yet, which makes a typo'd year an error rather than a silent hole in
     * the revenue figures.
     */
    public function test_a_payment_cannot_predate_the_invoice_being_issued(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'paid_on' => $invoice->issued_at->clone()->subDay()->toDateString(),
        ])->assertSessionHasErrors('paid_on');

        // The typo the floor exists to catch.
        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'paid_on' => $invoice->issued_at->clone()->subYear()->toDateString(),
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, Payment::count());
    }
}
