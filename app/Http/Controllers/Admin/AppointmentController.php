<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AppointmentConfirmed;
use App\Mail\AppointmentDeclined;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Appointments/Index', [
            'patients' => Patient::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'requests' => Appointment::with('patient')
                ->where('status', 'requested')
                ->orderBy('preferred_date')
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'patient_name' => $appointment->patient->full_name,
                    'patient_email' => $appointment->patient->email,
                    'patient_phone' => $appointment->patient->phone,
                    'service_interest' => $appointment->service_interest,
                    'dentist_preference' => $appointment->dentist_preference,
                    'preferred_date' => $appointment->preferred_date?->toDateString(),
                    'preferred_time_of_day' => $appointment->preferred_time_of_day,
                    'notes' => $appointment->notes,
                ]),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $events = Appointment::with(['patient', 'provider'])
            ->whereBetween('start_time', [
                Carbon::parse($validated['start']),
                Carbon::parse($validated['end']),
            ])
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

        $this->assertNoConflict(
            (int) $validated['provider_id'],
            Carbon::parse($validated['start_time']),
            Carbon::parse($validated['end_time']),
        );

        $validated['status'] = 'scheduled';

        Appointment::create($validated);

        return back();
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $originalStatus = $appointment->status;

        $validated = $request->validate([
            'provider_id' => ['sometimes', 'required', 'exists:providers,id'],
            'start_time' => ['sometimes', 'required', 'date'],
            'end_time' => ['sometimes', 'required', 'date', 'after:start_time'],
            'type' => ['sometimes', 'required', Rule::in(Appointment::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(Appointment::STATUSES)],
        ]);

        // The values this appointment will actually hold once saved, whether
        // they arrived in this request or were already on the record.
        $status = $validated['status'] ?? $appointment->status;
        $providerId = isset($validated['provider_id'])
            ? (int) $validated['provider_id']
            : $appointment->provider_id;
        $startTime = isset($validated['start_time'])
            ? Carbon::parse($validated['start_time'])
            : $appointment->start_time;
        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $appointment->end_time;
        $type = $validated['type'] ?? $appointment->type;

        if ($status === 'scheduled') {
            $this->assertSchedulable($startTime, $endTime, $providerId, $type);
        }

        if ($providerId && $startTime && $endTime && ! in_array($status, Appointment::SLOT_FREEING_STATUSES, true)) {
            $this->assertNoConflict($providerId, $startTime, $endTime, $appointment->id);
        }

        $appointment->update($validated);

        $this->notifyPatientOfRequestOutcome($originalStatus, $appointment);

        return back();
    }

    /**
     * A guest submitting a request has no account and no other way to find
     * out what happened to it, so we email them — but only the moment their
     * request is actually resolved. Editing an already-scheduled
     * appointment's time, or moving it to completed/cancelled/no_show,
     * stays silent.
     */
    private function notifyPatientOfRequestOutcome(string $originalStatus, Appointment $appointment): void
    {
        if ($originalStatus !== 'requested') {
            return;
        }

        match ($appointment->status) {
            'scheduled' => Mail::to($appointment->patient->email)->send(new AppointmentConfirmed($appointment)),
            'declined' => Mail::to($appointment->patient->email)->send(new AppointmentDeclined($appointment)),
            default => null,
        };
    }

    /**
     * A scheduled appointment must be a complete one. Without this, a request
     * could be marked scheduled with no start_time — and the FullCalendar feed
     * filters on start_time, so it would look confirmed but never appear.
     */
    private function assertSchedulable(?Carbon $startTime, ?Carbon $endTime, ?int $providerId, ?string $type): void
    {
        $missing = [];

        if (! $startTime) {
            $missing['start_time'] = 'A start time is required to schedule an appointment.';
        }

        if (! $endTime) {
            $missing['end_time'] = 'An end time is required to schedule an appointment.';
        }

        if (! $providerId) {
            $missing['provider_id'] = 'A provider is required to schedule an appointment.';
        }

        if (! $type) {
            $missing['type'] = 'A type is required to schedule an appointment.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function assertNoConflict(int $providerId, Carbon $startTime, Carbon $endTime, ?int $ignoreId = null): void
    {
        if (Appointment::hasConflict($providerId, $startTime, $endTime, $ignoreId)) {
            throw ValidationException::withMessages([
                'start_time' => 'This provider already has an appointment overlapping that time.',
            ]);
        }
    }
}
