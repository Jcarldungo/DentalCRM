<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Inertia\Inertia;
use Inertia\Response;

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
            'todaysSchedule' => $appointments->where('status', 'scheduled')->map($forBoard)->values(),
            'waiting' => $appointments->where('status', 'checked_in')->map($forBoard)->values(),
            'nowServing' => $appointments->where('status', 'in_treatment')->map($forBoard)->values(),
            'completed' => $appointments->where('status', 'completed')->map($forBoard)->values(),
        ]);
    }
}
