<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Patient;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $dueForRecall = Patient::dueForRecall()->map(fn (Patient $patient) => [
            'id' => $patient->id,
            'full_name' => $patient->full_name,
            'due_date' => $patient->recall_due_date->toDateString(),
            'last_cleaning_at' => $patient->recall_last_cleaning_at->toDateString(),
        ])->values();

        $outstandingInvoices = Invoice::where('status', 'issued')
            ->with(['items', 'payments'])
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->balance() > 0);

        return Inertia::render('Dashboard', [
            'dueForRecall' => $dueForRecall,
            'outstanding' => [
                'total' => round($outstandingInvoices->sum(fn (Invoice $invoice) => $invoice->balance()), 2),
                'count' => $outstandingInvoices->count(),
            ],
        ]);
    }
}
