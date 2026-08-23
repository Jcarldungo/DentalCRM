<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
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
