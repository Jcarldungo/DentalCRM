<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QueueController extends Controller
{
    /**
     * Today's front-desk board: today's appointments, grouped by where the
     * patient is in their visit. Read once, grouped in memory — the table
     * is small enough at this scale (see CLAUDE.md Known gaps) that a
     * single query beats four near-identical ones.
     */
    public function index(): Response
    {
        $appointments = Appointment::with(['patient', 'provider'])
            ->whereDate('start_time', now()->toDateString())
            ->whereIn('status', ['scheduled', 'checked_in', 'in_treatment', 'completed'])
            ->orderBy('start_time')
            ->get();

        $forBoard = fn (Appointment $appointment) => [
            'id' => $appointment->id,
            'patient_name' => $appointment->patient->full_name,
            'provider_name' => $appointment->provider->name,
            'type' => $appointment->type,
            'start_time' => $appointment->start_time->toIso8601String(),
            'end_time' => $appointment->end_time->toIso8601String(),
        ];

        return Inertia::render('Queue/Index', [
            'patients' => Patient::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'todaysSchedule' => $appointments->where('status', 'scheduled')->map($forBoard)->values(),
            'waiting' => $appointments->where('status', 'checked_in')->map($forBoard)->values(),
            'nowServing' => $appointments->where('status', 'in_treatment')->map($forBoard)->values(),
            'completed' => $appointments->where('status', 'completed')->map($forBoard)->values(),
        ]);
    }

    /**
     * A walk-in has no pre-existing appointment, so it skips Today's
     * Schedule entirely and lands directly in Waiting. Fixed 30-minute
     * block: there's no duration-by-appointment-type concept in the
     * codebase yet, and this phase doesn't add one.
     */
    public function storeWalkIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'type' => ['required', Rule::in(Appointment::TYPES)],
        ]);

        $start = now();
        $end = $start->clone()->addMinutes(30);

        if (Appointment::hasConflict((int) $validated['provider_id'], $start, $end)) {
            throw ValidationException::withMessages([
                'provider_id' => 'This provider already has an appointment overlapping that time.',
            ]);
        }

        Appointment::create([
            'patient_id' => $validated['patient_id'],
            'provider_id' => $validated['provider_id'],
            'type' => $validated['type'],
            'status' => 'checked_in',
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return back();
    }
}
