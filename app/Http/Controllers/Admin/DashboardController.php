<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'today' => $this->today(),
            'requests' => $this->pendingRequests(),
            'dueForRecall' => $this->dueForRecall(),
            'outstanding' => $this->outstanding(),
            'inventory' => $this->inventory(),
        ]);
    }

    /**
     * The state of the day being worked. The dashboard previously showed
     * recall, balances, and stock — all true, none of it about today —
     * so a receptionist opening the app learned nothing about the clinic
     * they were standing in.
     *
     * One grouped query rather than four counts, matching
     * QueueController's read of the same rows.
     *
     * @return array<string, mixed>
     */
    private function today(): array
    {
        $counts = Appointment::query()
            ->whereDate('start_time', now()->toDateString())
            ->whereIn('status', Appointment::BOARD_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $next = Appointment::with(['patient', 'provider'])
            ->whereDate('start_time', now()->toDateString())
            ->where('status', 'scheduled')
            ->where('start_time', '>=', now())
            ->orderBy('start_time')
            ->first();

        return [
            'date' => now()->toDateString(),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'waiting' => (int) ($counts['checked_in'] ?? 0),
            'in_treatment' => (int) ($counts['in_treatment'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'next' => $next ? [
                'id' => $next->id,
                'patient_id' => $next->patient_id,
                'patient_name' => $next->patient->full_name,
                'provider_name' => $next->provider?->name,
                'type' => $next->type,
                'start_time' => $next->start_time->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Guest appointment requests waiting on staff. This is the one item
     * on the dashboard with someone on the other end of it.
     *
     * @return array<string, mixed>
     */
    private function pendingRequests(): array
    {
        $pending = Appointment::with('patient')
            ->where('status', 'requested')
            ->orderBy('preferred_date')
            ->get();

        return [
            'count' => $pending->count(),
            'oldest_days' => $pending->isEmpty()
                ? null
                : (int) $pending->min('created_at')->startOfDay()->diffInDays(now()->startOfDay()),
            'items' => $pending->take(4)->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'patient_name' => $appointment->patient->full_name,
                'service_interest' => $appointment->service_interest,
                'preferred_date' => $appointment->preferred_date?->toDateString(),
                'preferred_time_of_day' => $appointment->preferred_time_of_day,
            ])->values(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function dueForRecall(): \Illuminate\Support\Collection
    {
        return Patient::dueForRecall()->map(fn (Patient $patient) => [
            'id' => $patient->id,
            'full_name' => $patient->full_name,
            'due_date' => $patient->recall_due_date->toDateString(),
            'last_cleaning_at' => $patient->recall_last_cleaning_at->toDateString(),
            'overdue_days' => (int) $patient->recall_due_date->startOfDay()->diffInDays(now()->startOfDay()),
        ])->values();
    }

    /**
     * Two aggregates, not a full read of every issued invoice with its
     * items and payments.
     *
     * @return array<string, mixed>
     */
    private function outstanding(): array
    {
        return [
            'total' => round((float) Invoice::outstanding()->sum(DB::raw(Invoice::balanceSql())), 2),
            'count' => Invoice::outstanding()->count(),
        ];
    }

    /**
     * Two counts, not a full read of every item's movement ledger.
     *
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        return [
            'low_count' => InventoryItem::lowStock()->count(),
            'expiring_count' => InventoryItem::expiringSoon()->count(),
        ];
    }
}
