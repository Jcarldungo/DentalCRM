<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ToothCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Patients/Index', [
            'patients' => Patient::orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function show(Patient $patient): Response
    {
        return Inertia::render('Patients/Show', [
            'patient' => $patient,
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
            'providers' => Provider::orderBy('name')->get(['id', 'name']),
            'appointments' => $patient->appointments()
                ->orderByDesc('start_time')
                ->get(['id', 'start_time', 'type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        Patient::create($validated);

        return back();
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $this->validated($request);

        $patient->update($validated);

        return back();
    }

    public function destroy(Patient $patient): RedirectResponse
    {
        $patient->delete();

        return back();
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'recall_interval_months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
    }
}
