<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A per-provider clinical-prep view: one date's scheduled appointments
 * for the chosen provider (or all), each row carrying how much open
 * clinical work that patient has. Read-only — the workspace writes
 * nothing; clinical edits happen on /patients/{patient}.
 */
class WorkspaceController extends Controller
{
    /**
     * Appointment statuses a prep view cares about — identical to
     * QueueController::index(). 'requested' has no start_time, and
     * cancelled/declined/no_show are not part of preparing for the day.
     */
    private const SHOWN_STATUSES = ['scheduled', 'checked_in', 'in_treatment', 'completed'];

    /** Treatment-plan statuses that still represent work to do. */
    private const OPEN_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'exists:providers,id'],
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'])
            : Carbon::today();
        $providerId = $validated['provider_id'] ?? null;

        $appointments = Appointment::query()
            ->with('patient:id,first_name,last_name,date_of_birth')
            ->whereDate('start_time', $date)
            ->whereIn('status', self::SHOWN_STATUSES)
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $patientIds = $appointments->pluck('patient_id')->unique()->values();

        $openTreatments = TreatmentPlanItem::query()
            ->whereIn('patient_id', $patientIds)
            ->whereIn('status', self::OPEN_TREATMENT_STATUSES)
            ->selectRaw('patient_id, COUNT(*) as total')
            ->groupBy('patient_id')
            ->pluck('total', 'patient_id');

        $activePrescriptions = Prescription::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', 'active')
            ->selectRaw('patient_id, COUNT(*) as total')
            ->groupBy('patient_id')
            ->pluck('total', 'patient_id');

        return Inertia::render('Workspace/Index', [
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'selectedProviderId' => $providerId !== null ? (int) $providerId : null,
            'date' => $date->toDateString(),
            'appointments' => $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'patient_name' => $appointment->patient->full_name,
                'patient_age' => $appointment->patient->date_of_birth
                    ? (int) $appointment->patient->date_of_birth->diffInYears(now())
                    : null,
                'type' => $appointment->type,
                'status' => $appointment->status,
                'start_time' => $appointment->start_time->toIso8601String(),
                'end_time' => $appointment->end_time?->toIso8601String(),
                'notes' => $appointment->notes,
                'open_treatment_count' => (int) ($openTreatments[$appointment->patient_id] ?? 0),
                'active_prescription_count' => (int) ($activePrescriptions[$appointment->patient_id] ?? 0),
            ]),
        ]);
    }
}
