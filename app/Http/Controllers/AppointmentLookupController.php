<?php

namespace App\Http\Controllers;

use App\Mail\AppointmentLookupLink;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentLookupController extends Controller
{
    private const CONFIRMATION_MESSAGE = "If that email has any appointments with us, we've sent a link to view them.";

    public function create(): Response
    {
        return Inertia::render('Public/AppointmentLookup');
    }

    /**
     * Same response whether or not the email matches a patient — otherwise
     * this endpoint would let anyone check which emails are in the system.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $patient = Patient::whereRaw('LOWER(email) = ?', [Str::lower(trim($validated['email']))])->first();

        if ($patient) {
            Mail::to($patient->email)->send(new AppointmentLookupLink($patient));
        }

        return back()->with('status', self::CONFIRMATION_MESSAGE);
    }

    public function show(Patient $patient): Response
    {
        $appointments = $patient->appointments()
            ->with('provider')
            ->orderByRaw('COALESCE(start_time, preferred_date) DESC')
            ->get()
            ->map(fn ($appointment) => [
                'id' => $appointment->id,
                'service' => $appointment->service_interest ?? ucfirst($appointment->type ?? 'Appointment'),
                'status' => $appointment->status,
                'provider' => $appointment->provider?->name,
                'start_time' => $appointment->start_time?->toIso8601String(),
                'end_time' => $appointment->end_time?->toIso8601String(),
                'preferred_date' => $appointment->preferred_date?->toDateString(),
                'preferred_time_of_day' => $appointment->preferred_time_of_day,
            ]);

        return Inertia::render('Public/AppointmentLookupResults', [
            'patientFirstName' => $patient->first_name,
            'appointments' => $appointments,
        ]);
    }
}
