<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\ToothCondition;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    /**
     * The patient list, searched and paginated in the database.
     *
     * This used to load every patient row and hand the whole set to the
     * page with no search — fine for a demo, useless for a clinic with
     * four thousand of them, which is the first list that stops working
     * as this is sold.
     *
     * Each row carries the three things a receptionist looks a patient up
     * for: when they were last seen, when they are next due in, and what
     * they owe. All three are aggregates on the page of results, not
     * per-row queries.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');

        $patients = Patient::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(function ($scoped) use ($like) {
                    $scoped->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereRaw("concat(first_name, ' ', last_name) like ?", [$like]);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Patients/Index', [
            'patients' => $patients->through(fn (Patient $patient) => [
                'id' => $patient->id,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'full_name' => $patient->full_name,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'emergency_contact_name' => $patient->emergency_contact_name,
                'emergency_contact_phone' => $patient->emergency_contact_phone,
                'notes' => $patient->notes,
                'recall_interval_months' => $patient->recall_interval_months,
            ]),
            'summaries' => $this->summariesFor($patients->getCollection()),
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Last visit, next appointment, and outstanding balance for the
     * patients on this page — three grouped queries rather than three per
     * row.
     *
     * @param  \Illuminate\Support\Collection<int, Patient>  $patients
     * @return array<int, array<string, mixed>>
     */
    protected function summariesFor(\Illuminate\Support\Collection $patients): array
    {
        $ids = $patients->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $lastVisit = Appointment::whereIn('patient_id', $ids)
            ->where('status', 'completed')
            ->selectRaw('patient_id, max(start_time) as at')
            ->groupBy('patient_id')
            ->pluck('at', 'patient_id');

        $nextVisit = Appointment::whereIn('patient_id', $ids)
            ->whereIn('status', ['scheduled', 'checked_in', 'in_treatment'])
            ->where('start_time', '>=', now())
            ->selectRaw('patient_id, min(start_time) as at')
            ->groupBy('patient_id')
            ->pluck('at', 'patient_id');

        $balances = Invoice::whereIn('patient_id', $ids)
            ->outstanding()
            ->selectRaw('patient_id, sum('.Invoice::balanceSql().') as owed')
            ->groupBy('patient_id')
            ->pluck('owed', 'patient_id');

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => [
            'last_visit' => $lastVisit[$id] ?? null,
            'next_visit' => $nextVisit[$id] ?? null,
            'balance' => round((float) ($balances[$id] ?? 0), 2),
        ]])->all();
    }

    public function show(Patient $patient): Response
    {
        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            // The patient header. Everything here is already loaded or
            // derived below except the next appointment; it exists so the
            // page opens with who this is and what state they are in,
            // rather than a six-field definition list.
            'summary' => [
                'age' => $patient->date_of_birth
                    ? (int) $patient->date_of_birth->diffInYears(now())
                    : null,
                'next_appointment' => $patient->appointments()
                    ->with('provider')
                    ->whereIn('status', ['scheduled', 'checked_in', 'in_treatment'])
                    ->where('start_time', '>=', now())
                    ->orderBy('start_time')
                    ->first()
                    ?->only(['id', 'start_time', 'type', 'status']),
                'last_visit' => $patient->appointments()
                    ->where('status', 'completed')
                    ->max('start_time'),
            ],
            'dentalRecords' => $patient->dentalRecords()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (DentalRecord $record) => [
                    'id' => $record->id,
                    'type' => $record->type,
                    'provider_name' => $record->provider?->name,
                    'appointment_start_time' => $record->appointment?->start_time?->toIso8601String(),
                    'examination' => $record->examination,
                    'diagnosis' => $record->diagnosis,
                    'procedure' => $record->procedure,
                    'notes' => $record->notes,
                    'created_at' => $record->created_at->toIso8601String(),
                    'creator_name' => $record->creator->name,
                ]),
            'toothConditions' => $patient->toothConditions()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (ToothCondition $condition) => [
                    'id' => $condition->id,
                    'tooth_number' => $condition->tooth_number,
                    'condition' => $condition->condition,
                    'notes' => $condition->notes,
                    'provider_name' => $condition->provider?->name,
                    'appointment_start_time' => $condition->appointment?->start_time?->toIso8601String(),
                    'created_at' => $condition->created_at->toIso8601String(),
                    'creator_name' => $condition->creator->name,
                ]),
            'treatmentPlanItems' => $patient->treatmentPlanItems()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (TreatmentPlanItem $item) => [
                    'id' => $item->id,
                    'tooth_number' => $item->tooth_number,
                    'treatment' => $item->treatment,
                    'estimated_cost' => $item->estimated_cost,
                    'priority' => $item->priority,
                    'status' => $item->status,
                    'notes' => $item->notes,
                    'provider_name' => $item->provider?->name,
                    'appointment_start_time' => $item->appointment?->start_time?->toIso8601String(),
                    'created_at' => $item->created_at->toIso8601String(),
                    'creator_name' => $item->creator->name,
                ]),
            'prescriptions' => $patient->prescriptions()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (Prescription $rx) => [
                    'id' => $rx->id,
                    'medication' => $rx->medication,
                    'dosage' => $rx->dosage,
                    'frequency' => $rx->frequency,
                    'duration' => $rx->duration,
                    'quantity' => $rx->quantity,
                    'instructions' => $rx->instructions,
                    'status' => $rx->status,
                    'discontinued_at' => $rx->discontinued_at?->toIso8601String(),
                    'discontinued_reason' => $rx->discontinued_reason,
                    'provider_name' => $rx->provider?->name,
                    'appointment_start_time' => $rx->appointment?->start_time?->toIso8601String(),
                    'created_at' => $rx->created_at->toIso8601String(),
                    'creator_name' => $rx->creator->name,
                ]),
            'invoices' => $patient->invoices()
                ->with(['items', 'payments'])
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number(),
                    'status' => $invoice->status,
                    'total' => $invoice->total(),
                    'amount_paid' => $invoice->amountPaid(),
                    'balance' => $invoice->balance(),
                    'is_paid' => $invoice->isPaid(),
                    'created_at' => $invoice->created_at->toIso8601String(),
                ]),
            // The billing tab used to re-declare the billable status set
            // client-side; it receives the server's list instead, so
            // TreatmentPlanItem::BILLABLE_STATUSES is the only definition.
            'billableTreatmentItems' => $patient->treatmentPlanItems()
                ->whereIn('status', TreatmentPlanItem::BILLABLE_STATUSES)
                ->orderBy('id')
                ->get()
                ->map(fn (TreatmentPlanItem $item) => [
                    'id' => $item->id,
                    'label' => $item->treatment.($item->tooth_number ? ' · tooth '.$item->tooth_number : ''),
                    'treatment' => $item->treatment,
                    'estimated_cost' => (float) $item->estimated_cost,
                ])->values(),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'appointments' => $patient->appointments()
                ->orderByDesc('start_time')
                ->get(['id', 'start_time', 'type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $patient = Patient::create($validated);

        return back()->with('success', "{$patient->full_name} was added.");
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $this->validated($request, $patient);

        $patient->update($validated);

        return back()->with('success', 'Patient details saved.');
    }

    /**
     * Deleting a patient cascades through appointments, dental records,
     * tooth conditions, treatment-plan items, prescriptions, and the whole
     * billing ledger including recorded payments — five of which the app
     * documents as append-only. So this refuses once anything is attached,
     * mirroring the guard `ProviderController::destroy` already applies.
     * A patient created by mistake moments ago, with nothing attached, is
     * still removable, which is the only use this route has left.
     */
    public function destroy(Patient $patient): RedirectResponse
    {
        if ($patient->hasClinicalOrBillingHistory()) {
            AuditLog::record('patient.delete_refused', $patient, $patient->full_name);

            return back()->withErrors([
                'patient' => 'This patient has appointments, clinical records, or billing history and cannot be deleted.',
            ]);
        }

        $name = $patient->full_name;
        AuditLog::record('patient.deleted', $patient, $name);
        $patient->delete();

        return back()->with('success', "{$name} was removed.");
    }

    protected function validated(Request $request, ?Patient $patient = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // A future date of birth is a typo, not a patient.
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Unique because patients.email carries a unique index — the
            // public booking flow matches an existing patient on it, so a
            // duplicate would make that match ambiguous.
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('patients', 'email')->ignore($patient),
            ],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'recall_interval_months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
    }
}
