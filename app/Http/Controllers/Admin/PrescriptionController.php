<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A prescription's clinical content — medication, dosage, frequency,
 * duration, quantity, instructions, and both FK links — is fixed at
 * creation. The only post-creation change is a one-way active ->
 * discontinued flip via update(). There is deliberately no destroy()
 * here, no matching route, and no UI control to reach one; a wrong
 * prescription is discontinued and re-entered, not rewritten.
 */
class PrescriptionController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'medication' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'frequency' => ['required', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
        ]);

        // created_by is never trusted from the request and isn't $fillable;
        // set it explicitly. status is left to its 'active' column default.
        $rx = $patient->prescriptions()->make($validated);
        $rx->created_by = $request->user()->id;
        $rx->save();

        return back();
    }
}
