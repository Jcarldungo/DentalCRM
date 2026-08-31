<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording a payment against an issued invoice, and nothing else.
 * Payments are append-only — there is deliberately no update() or
 * destroy() here, and no matching route. Overpayment is rejected: the
 * amount is capped at the invoice's current balance.
 */
class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->status === 'issued', 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            // A back-dated payment reduces the balance immediately but lands
            // outside every /reports range, so collected revenue silently
            // under-reports while A/R correctly drops. No lower bound: a
            // floor would need a business rule this app doesn't have.
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = $request->user()->id;

        DB::transaction(function () use ($invoice, $validated, $userId) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            // Re-check status, not just the balance: the pre-lock check
            // above ran against a snapshot, so a concurrent void could
            // otherwise land a payment on a voided invoice. This makes the
            // invariant symmetric with InvoiceController, which already
            // refuses to void an invoice that has payments.
            abort_unless($locked->status === 'issued', 403);

            $locked->load(['items', 'payments']);
            $balance = $locked->balance();

            if ((float) $validated['amount'] > $balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment of '.number_format((float) $validated['amount'], 2)
                        .' exceeds the outstanding balance of '.number_format($balance, 2).'.',
                ]);
            }

            $payment = $locked->payments()->make([
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'paid_on' => $validated['paid_on'] ?? now()->toDateString(),
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);
            $payment->created_by = $userId;
            $payment->save();
        });

        AuditLog::record('payment.recorded', $invoice, $invoice->number(), [
            'amount' => (float) $validated['amount'],
            'method' => $validated['method'],
        ]);

        return back()->with('success', 'Payment recorded.');
    }
}
