<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Appointments/Index', [
            'patients' => Patient::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $events = Appointment::with(['patient', 'provider'])
            ->whereBetween('start_time', [$validated['start'], $validated['end']])
            ->get()
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'title' => $appointment->patient->full_name . ' — ' . ucfirst($appointment->type),
                'start' => $appointment->start_time->toIso8601String(),
                'end' => $appointment->end_time->toIso8601String(),
                'extendedProps' => [
                    'provider' => $appointment->provider->name,
                    'type' => $appointment->type,
                    'status' => $appointment->status,
                ],
            ]);

        return response()->json($events);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'type' => ['required', Rule::in(Appointment::TYPES)],
        ]);

        $validated['status'] = 'scheduled';

        Appointment::create($validated);

        return back();
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $validated = $request->validate([
            'start_time' => ['sometimes', 'required', 'date'],
            'end_time' => ['sometimes', 'required', 'date', 'after:start_time'],
            'type' => ['sometimes', 'required', Rule::in(Appointment::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(Appointment::STATUSES)],
        ]);

        $appointment->update($validated);

        return back();
    }
}
