<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoices for a patient. store() always creates a 'draft'; show()
 * projects the invoice with every money figure derived from its loaded
 * items/payments. update() (Task 3) handles draft edits and the
 * draft -> issued -> void state machine. There is no destroy().
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort(404);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($request->has('status')) {
            return $this->transition($request, $invoice);
        }

        abort_unless($invoice->status === 'draft', 403);

        $validated = $this->validatePayload($request, $invoice->patient_id);

        $invoice->update([
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);
        $this->syncItems($invoice, $validated['items']);

        return back();
    }

    /**
     * The draft -> issued -> void state machine. The only legal moves
     * are draft->issued, draft->void, and issued->void (the last only
     * while the invoice has no payments). Everything else is a
     * validation error on 'status'.
     */
    protected function transition(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Invoice::STATUSES)],
        ]);

        $from = $invoice->status;
        $to = $validated['status'];

        $legal = ($from === 'draft' && $to === 'issued')
            || ($from === 'draft' && $to === 'void')
            || ($from === 'issued' && $to === 'void');

        if (! $legal) {
            throw ValidationException::withMessages([
                'status' => "An invoice cannot move from {$from} to {$to}.",
            ]);
        }

        if ($to === 'issued' && $invoice->items()->count() < 1) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one line item before issuing this invoice.',
            ]);
        }

        if ($from === 'issued' && $to === 'void' && $invoice->payments()->count() > 0) {
            throw ValidationException::withMessages([
                'status' => 'An invoice with recorded payments cannot be voided.',
            ]);
        }

        $invoice->status = $to;
        if ($to === 'issued') {
            $invoice->issued_at = now();
        }
        if ($to === 'void') {
            $invoice->voided_at = now();
        }
        $invoice->save();

        return back();
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items.treatmentPlanItem', 'items.provider', 'payments.creator', 'patient', 'creator']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $this->present($invoice),
            'treatmentPlanItems' => $this->linkableTreatmentItems($invoice->patient_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['patient_id' => ['required', 'exists:patients,id']]);
        $patientId = (int) $request->input('patient_id');

        $validated = $this->validatePayload($request, $patientId);

        $invoice = new Invoice([
            'patient_id' => $patientId,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);
        $invoice->status = 'draft';
        $invoice->created_by = $request->user()->id;
        $invoice->save();

        $this->syncItems($invoice, $validated['items']);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Validate the create/edit payload (everything except patient_id)
     * and reject a discount larger than the line-item subtotal.
     *
     * @return array{items: array<int, array<string, mixed>>, discount_amount?: string|null, notes?: string|null}
     */
    protected function validatePayload(Request $request, int $patientId): array
    {
        $validated = $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.treatment_plan_item_id' => [
                'nullable',
                Rule::exists('treatment_plan_items', 'id')->where('patient_id', $patientId),
            ],
        ]);

        $subtotal = collect($validated['items'])->sum(fn ($item) => (float) $item['amount']);
        if ((float) ($validated['discount_amount'] ?? 0) > $subtotal) {
            throw ValidationException::withMessages([
                'discount_amount' => 'The discount cannot exceed the line-item subtotal.',
            ]);
        }

        return $validated;
    }

    /** Replace an invoice's line items with the given set. */
    protected function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $item) {
            $tpiId = $item['treatment_plan_item_id'] ?? null;

            $invoice->items()->create([
                'treatment_plan_item_id' => $tpiId,
                'provider_id' => $tpiId
                    ? TreatmentPlanItem::whereKey($tpiId)->value('provider_id')
                    : null,
                'description' => $item['description'],
                'amount' => $item['amount'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number(),
            'status' => $invoice->status,
            'patient' => [
                'id' => $invoice->patient->id,
                'full_name' => $invoice->patient->full_name,
            ],
            'notes' => $invoice->notes,
            'discount_amount' => (float) $invoice->discount_amount,
            'subtotal' => $invoice->subtotal(),
            'total' => $invoice->total(),
            'amount_paid' => $invoice->amountPaid(),
            'balance' => $invoice->balance(),
            'is_paid' => $invoice->isPaid(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'created_at' => $invoice->created_at->toIso8601String(),
            'creator_name' => $invoice->creator->name,
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'amount' => (float) $item->amount,
                'treatment_plan_item_id' => $item->treatment_plan_item_id,
                'treatment_plan_item_label' => $item->treatmentPlanItem
                    ? $this->treatmentLabel($item->treatmentPlanItem)
                    : null,
                'provider_name' => $item->provider?->name,
            ])->values(),
            'payments' => $invoice->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'paid_on' => $payment->paid_on->toDateString(),
                'reference' => $payment->reference,
                'note' => $payment->note,
                'created_at' => $payment->created_at->toIso8601String(),
                'creator_name' => $payment->creator->name,
            ])->values(),
        ];
    }

    /**
     * The patient's treatment-plan items worth putting on a bill:
     * planned / scheduled / in_progress / completed. This status list is
     * duplicated in BillingTab.jsx — see CLAUDE.md "Known gaps".
     */
    protected function linkableTreatmentItems(int $patientId): Collection
    {
        return TreatmentPlanItem::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ['planned', 'scheduled', 'in_progress', 'completed'])
            ->orderBy('id')
            ->get()
            ->map(fn (TreatmentPlanItem $item) => [
                'id' => $item->id,
                'label' => $this->treatmentLabel($item),
                'estimated_cost' => (float) $item->estimated_cost,
            ]);
    }

    protected function treatmentLabel(TreatmentPlanItem $item): string
    {
        return $item->treatment
            .($item->tooth_number ? ' · tooth '.$item->tooth_number : '');
    }
}
