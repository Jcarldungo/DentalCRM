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
use Illuminate\Support\Facades\DB;
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
    /**
     * The invoice list, filtered and paginated in the database.
     *
     * This used to load every invoice with every line item and every
     * payment, derive the money in PHP, and filter the resulting
     * collection — three unbounded reads to render one page. The figures
     * are now correlated subqueries (see Invoice::balanceSql()), so a
     * clinic with ten years of billing renders the same page as one with
     * ten invoices.
     *
     * The totals strip is a separate aggregate over the whole filtered
     * set, because "how much is outstanding" must not mean "on this page".
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'draft', 'outstanding', 'paid', 'void'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $filter = $validated['status'] ?? 'all';
        $search = trim($validated['search'] ?? '');

        $query = Invoice::query()
            ->withMoney()
            ->with('patient:id,first_name,last_name')
            ->when($filter === 'draft', fn ($q) => $q->where('status', 'draft'))
            ->when($filter === 'void', fn ($q) => $q->where('status', 'void'))
            ->when($filter === 'outstanding', fn ($q) => $q->outstanding())
            ->when($filter === 'paid', fn ($q) => $q->settled())
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $q->where(function ($scoped) use ($like, $search) {
                    $scoped
                        ->whereHas('patient', fn ($patient) => $patient
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhereRaw("concat(first_name, ' ', last_name) like ?", [$like]))
                        // "INV-000012", "000012", and "12" all find it.
                        ->orWhere('invoices.id', (int) ltrim(preg_replace('/[^0-9]/', '', $search), '0') ?: 0);
                });
            });

        $invoices = (clone $query)
            ->latest('invoices.created_at')
            ->latest('invoices.id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices->through(fn (Invoice $invoice) => $invoice->toListArray()),
            'summary' => [
                'count' => $invoices->total(),
                'outstanding' => round((float) (clone $query)->outstanding()->sum(DB::raw(Invoice::balanceSql())), 2),
            ],
            'filters' => ['status' => $filter, 'search' => $search],
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        // Dual-mode PATCH: when both `status` and edit fields are present,
        // `status` wins (transition mode) and the edit fields are ignored.
        // `filled`, not `has`: `has` is true for a present-but-null key, so
        // an edit payload carrying `status: null` would route into
        // transition() and die on "The status field is required" instead of
        // saving. The whole draft-freeze guarantee rests on this branch.
        if ($request->filled('status')) {
            return $this->transition($request, $invoice);
        }

        abort_unless($invoice->status === 'draft', 403);

        $validated = $this->validatePayload($request, $invoice->patient_id);

        DB::transaction(function () use ($invoice, $validated) {
            $invoice->update([
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
            $this->syncItems($invoice, $validated['items']);
        });

        return back()->with('success', 'Invoice saved.');
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

        $to = $validated['status'];

        // Every part of the decision is made against `$locked`, inside the
        // transaction — the legality of the move, the "a draft needs line
        // items" rule, and the payment check. Deciding from the route-bound
        // instance instead let a concurrent {void} + {issued} pair both pass
        // their checks and resurrect a voided invoice.
        DB::transaction(function () use ($invoice, $to) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $from = $locked->status;

            $legal = ($from === 'draft' && $to === 'issued')
                || ($from === 'draft' && $to === 'void')
                || ($from === 'issued' && $to === 'void');

            if (! $legal) {
                throw ValidationException::withMessages([
                    'status' => "An invoice cannot move from {$from} to {$to}.",
                ]);
            }

            if ($to === 'issued' && $locked->items()->count() < 1) {
                throw ValidationException::withMessages([
                    'status' => 'Add at least one line item before issuing this invoice.',
                ]);
            }

            if ($from === 'issued' && $to === 'void' && $locked->payments()->count() > 0) {
                throw ValidationException::withMessages([
                    'status' => 'An invoice with recorded payments cannot be voided.',
                ]);
            }

            $locked->status = $to;
            if ($to === 'issued') {
                $locked->issued_at = now();
            }
            if ($to === 'void') {
                $locked->voided_at = now();
            }
            $locked->save();
        });

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

        $userId = $request->user()->id;

        $invoice = DB::transaction(function () use ($patientId, $validated, $userId) {
            $invoice = new Invoice([
                'patient_id' => $patientId,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
            $invoice->status = 'draft';
            $invoice->created_by = $userId;
            $invoice->save();

            $this->syncItems($invoice, $validated['items']);

            return $invoice;
        });

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
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
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

        $providerByTpi = TreatmentPlanItem::whereIn(
            'id',
            collect($items)->pluck('treatment_plan_item_id')->filter()->unique()->all(),
        )->pluck('provider_id', 'id');

        foreach ($items as $item) {
            $tpiId = $item['treatment_plan_item_id'] ?? null;

            $invoice->items()->create([
                'treatment_plan_item_id' => $tpiId,
                'provider_id' => $tpiId ? ($providerByTpi[$tpiId] ?? null) : null,
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
     * The patient's treatment-plan items worth putting on a bill.
     * BillingTab.jsx receives the same set as a prop rather than
     * re-declaring it.
     */
    protected function linkableTreatmentItems(int $patientId): Collection
    {
        return TreatmentPlanItem::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', TreatmentPlanItem::BILLABLE_STATUSES)
            ->orderBy('id')
            ->get()
            ->map(fn (TreatmentPlanItem $item) => [
                'id' => $item->id,
                'label' => $this->treatmentLabel($item),
                'treatment' => $item->treatment,
                'estimated_cost' => (float) $item->estimated_cost,
            ]);
    }

    protected function treatmentLabel(TreatmentPlanItem $item): string
    {
        return $item->treatment
            .($item->tooth_number ? ' · tooth '.$item->tooth_number : '');
    }
}
