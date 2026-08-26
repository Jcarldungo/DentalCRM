<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\ToothCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToothConditionController extends Controller
{
    /**
     * Append-only: there is deliberately no update()/destroy() here, no
     * matching routes, and no UI to reach them. A correction is a new
     * entry, not an edit to this one.
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'tooth_number' => ['required', 'integer', 'between:1,32'],
            'condition' => ['required', Rule::in(ToothCondition::CONDITIONS)],
            'notes' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
        ]);

        // created_by is never trusted from the request — and it isn't in
        // $fillable, so mass-assigning it through create() would silently
        // drop it. Set it explicitly via direct property assignment instead.
        $condition = $patient->toothConditions()->make($validated);
        $condition->created_by = $request->user()->id;
        $condition->save();

        return back();
    }
}
