<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Treatment plan items are mutable but never deletable: status, priority,
 * estimated_cost, and notes can change via update(), but there is
 * deliberately no destroy() here, no matching route, and no UI control to
 * reach one. Retiring an item means setting its status to 'cancelled', not
 * removing the row.
 */
class TreatmentPlanItemController extends Controller
{
    /**
     * Every new item starts at status 'planned' — status is never
     * accepted from the request here; it only ever changes via update().
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'treatment' => ['required', 'string', 'max:255'],
            'tooth_number' => ['nullable', 'integer', 'between:1,32'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'estimated_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'priority' => ['required', Rule::in(TreatmentPlanItem::PRIORITIES)],
            'notes' => ['nullable', 'string'],
        ]);

        // created_by is never trusted from the request — and it isn't in
        // $fillable, so mass-assigning it through create() would silently
        // drop it. Set it explicitly via direct property assignment instead.
        $item = $patient->treatmentPlanItems()->make([
            ...$validated,
            'status' => 'planned',
        ]);
        $item->created_by = $request->user()->id;
        $item->save();

        return back()->with('success', 'Treatment added to the plan.');
    }

    /**
     * Only status/priority/estimated_cost/notes are editable. treatment,
     * tooth_number, provider_id, and appointment_id are fixed at
     * creation — a wrong one is cancelled and re-entered, not rewritten,
     * so this method never touches them regardless of what the request
     * body contains.
     */
    public function update(Request $request, Patient $patient, TreatmentPlanItem $treatmentPlanItem): RedirectResponse
    {
        abort_unless($treatmentPlanItem->patient_id === $patient->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(TreatmentPlanItem::STATUSES)],
            'priority' => ['required', Rule::in(TreatmentPlanItem::PRIORITIES)],
            'estimated_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string'],
        ]);

        $treatmentPlanItem->update($validated);

        return back()->with('success', 'Treatment updated.');
    }
}
