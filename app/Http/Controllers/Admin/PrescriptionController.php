<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Prescription;
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

        return back()->with('success', 'Prescription added.');
    }

    /**
     * The discontinue action, and nothing else. Only status /
     * discontinued_at / discontinued_reason change here — drug fields in
     * the request body are never read, so clinical content cannot be
     * edited through this endpoint. A prescription can be discontinued
     * only once.
     */
    public function update(Request $request, Patient $patient, Prescription $prescription): RedirectResponse
    {
        abort_unless($prescription->patient_id === $patient->id, 404);
        abort_unless($prescription->status === 'active', 403);

        $validated = $request->validate([
            'discontinued_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // status / discontinued_at / discontinued_reason are intentionally
        // not $fillable — set them by direct assignment, not mass-assignment,
        // so nothing in the request body can reach them.
        $prescription->status = 'discontinued';
        $prescription->discontinued_at = now();
        $prescription->discontinued_reason = $validated['discontinued_reason'] ?? null;
        $prescription->save();

        AuditLog::record('prescription.discontinued', $prescription, $prescription->medication, [
            'patient_id' => $prescription->patient_id,
        ]);

        return back()->with('success', 'Prescription discontinued.');
    }
}
