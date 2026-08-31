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
use Illuminate\Support\Facades\Log;
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
            // So the calendar's status picker offers only legal moves
            // rather than all eight and a validation error.
            'transitions' => Appointment::TRANSITIONS,
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
                'end' => $appointment->end_time?->toIso8601String(),
                'extendedProps' => [
                    'patientId' => $appointment->patient_id,
                    'patientName' => $appointment->patient->full_name,
                    'provider' => $appointment->provider?->name,
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

        return back()->with('success', 'Appointment scheduled.');
    }

    /**
     * A partial update: only the fields present are changed.
     *
     * The payload is normalised first, because "present" has to mean
     * "actually carries a value". The calendar's edit dialog submits one
     * shared form whose patient_id and provider_id are blank in edit mode,
     * so a plain `sometimes|required` rejected every edit with "The
     * provider id field is required" — an error on a field that dialog
     * does not even show. The result was that an appointment's time or
     * status could not be changed from the calendar at all.
     *
     * Fixing it in the frontend alone would leave the next caller to
     * rediscover it, so an empty string is treated as absent here too.
     */
    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $originalStatus = $appointment->status;

        $request->replace(
            collect($request->all())
                ->reject(fn ($value) => $value === '' || $value === null)
                ->all()
        );

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

        // Is this move legal at all? See Appointment::TRANSITIONS — the
        // table forbids nonsense (nothing becomes `requested`; a completed
        // visit cannot be un-happened) while keeping a one-step way back
        // from every mis-click.
        if (! Appointment::canTransition($appointment->status, $status)) {
            throw ValidationException::withMessages([
                'status' => "An appointment cannot move from {$appointment->status} to {$status}.",
            ]);
        }

        // Every board status, not just 'scheduled'. /queue and /workspace
        // both project provider->name and end_time unconditionally, so a
        // request forced straight to checked_in with a null provider used
        // to 500 the queue page for every staff member, permanently.
        if (in_array($status, Appointment::BOARD_STATUSES, true)) {
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
     *
     * The status change is already saved by the time this runs, so a
     * delivery failure here must not fail the request: staff successfully
     * confirmed/declined the appointment regardless of whether the email
     * made it out. Logged instead, since a retry wouldn't re-trigger this
     * (the appointment is no longer 'requested').
     */
    private function notifyPatientOfRequestOutcome(string $originalStatus, Appointment $appointment): void
    {
        if ($originalStatus !== 'requested') {
            return;
        }

        $mailable = match ($appointment->status) {
            'scheduled' => new AppointmentConfirmed($appointment),
            'declined' => new AppointmentDeclined($appointment),
            default => null,
        };

        if ($mailable === null) {
            return;
        }

        try {
            Mail::to($appointment->patient->email)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Failed to email patient about their appointment request outcome.', [
                'appointment_id' => $appointment->id,
                'status' => $appointment->status,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * An appointment on the board must be a complete one. Without this, a
     * request could be marked scheduled with no start_time — and the
     * FullCalendar feed filters on start_time, so it would look confirmed
     * but never appear — or reach /queue with a null provider and crash it.
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
