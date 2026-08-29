<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Provider;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clinic reports: one read-only page with a date-range selector and three
 * sections (Revenue, Appointments, Patients), every figure a SQL
 * aggregate. No model, no migration, no write path — same shape as
 * WorkspaceController. See
 * docs/superpowers/specs/2026-08-30-reports-analytics-design.md.
 */
class ReportsController extends Controller
{
    private const RANGES = ['this_month', 'last_month', 'this_quarter', 'ytd', 'last_12_months', 'custom'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(self::RANGES)],
            'start' => ['required_if:range,custom', 'nullable', 'date'],
            'end' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:start'],
        ]);

        $range = $validated['range'] ?? 'this_month';
        [$start, $end] = $this->resolveRange($range, $validated['start'] ?? null, $validated['end'] ?? null);

        if ($start->diffInDays($end) > 400) {
            throw ValidationException::withMessages([
                'end' => 'The date range cannot exceed 400 days.',
            ]);
        }

        $bucket = $this->bucketFor($start, $end);

        return Inertia::render('Reports/Index', [
            'meta' => [
                'range' => $range,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $this->rangeLabel($range, $start, $end),
                'bucket' => $bucket,
            ],
            'revenue' => $this->revenue($start, $end, $bucket),
            'appointments' => [],
            'patients' => [],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(string $range, ?string $start, ?string $end): array
    {
        $today = Carbon::today();

        return match ($range) {
            'this_month' => [$today->clone()->startOfMonth(), $today->clone()->endOfDay()],
            'last_month' => [
                $today->clone()->subMonthNoOverflow()->startOfMonth(),
                $today->clone()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_quarter' => [$today->clone()->startOfQuarter(), $today->clone()->endOfDay()],
            'ytd' => [$today->clone()->startOfYear(), $today->clone()->endOfDay()],
            'last_12_months' => [$today->clone()->subMonthsNoOverflow(12)->startOfDay(), $today->clone()->endOfDay()],
            'custom' => [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()],
        };
    }

    private function bucketFor(Carbon $start, Carbon $end): string
    {
        $days = $start->diffInDays($end);

        return match (true) {
            $days <= 31 => 'day',
            $days <= 180 => 'week',
            default => 'month',
        };
    }

    /** @return list<string> ordered bucket keys (Y-m-d) spanning [start, end] */
    private function bucketKeys(Carbon $start, Carbon $end, string $bucket): array
    {
        [$anchor, $interval] = match ($bucket) {
            'day' => [$start->clone()->startOfDay(), '1 day'],
            'week' => [$start->clone()->startOfWeek(Carbon::MONDAY), '1 week'],
            'month' => [$start->clone()->startOfMonth(), '1 month'],
        };

        return collect(CarbonPeriod::create($anchor, $interval, $end))
            ->map(fn (Carbon $d) => $d->toDateString())
            ->all();
    }

    /**
     * A MariaDB expression that buckets $column to the same Y-m-d keys
     * bucketKeys() produces. $column is a trusted literal supplied by
     * this controller, never request input.
     */
    private function bucketExpr(string $column, string $bucket): string
    {
        return match ($bucket) {
            'day' => "DATE($column)",
            'week' => "DATE(DATE_SUB($column, INTERVAL WEEKDAY($column) DAY))",
            'month' => "DATE_FORMAT($column, '%Y-%m-01')",
        };
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, int|float>  $valuesByKey
     * @return list<array{bucket: string, value: int|float}>
     */
    private function fillSeries(array $keys, array $valuesByKey, int|float $zero = 0): array
    {
        return array_map(
            fn (string $key) => ['bucket' => $key, 'value' => $valuesByKey[$key] ?? $zero],
            $keys,
        );
    }

    private function revenue(Carbon $start, Carbon $end, string $bucket): array
    {
        $paidOnRange = [$start->toDateString(), $end->toDateString()];

        $collectedTotal = round((float) Payment::query()
            ->whereBetween('paid_on', $paidOnRange)
            ->sum('amount'), 2);

        $invoicedTotal = round((float) Invoice::query()
            ->where('status', '!=', 'void')
            ->whereBetween('issued_at', [$start, $end])
            ->withSum('items as items_total', 'amount')
            ->get(['id', 'discount_amount'])
            ->sum(fn (Invoice $i) => (float) $i->items_total - (float) $i->discount_amount), 2);

        $outstanding = Invoice::query()
            ->where('status', 'issued')
            ->with(['items', 'payments'])
            ->get()
            ->filter(fn (Invoice $i) => $i->balance() > 0);

        $keys = $this->bucketKeys($start, $end, $bucket);
        $collectedByBucket = Payment::query()
            ->whereBetween('paid_on', $paidOnRange)
            ->selectRaw($this->bucketExpr('paid_on', $bucket).' as bucket, SUM(amount) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        return [
            'collected_total' => $collectedTotal,
            'invoiced_total' => $invoicedTotal,
            'outstanding' => [
                'total' => round($outstanding->sum(fn (Invoice $i) => $i->balance()), 2),
                'count' => $outstanding->count(),
            ],
            'collected_trend' => [
                'bucket' => $bucket,
                'series' => $this->fillSeries($keys, $collectedByBucket, 0),
            ],
            'by_provider' => $this->revenueByProvider($start, $end),
            'by_treatment' => $this->revenueByTreatment($start, $end),
            'method_mix' => $this->methodMix($paidOnRange),
        ];
    }

    /** @return list<array{label: string, value: float}> */
    private function revenueByProvider(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', 'void')
            ->whereBetween('invoices.issued_at', [$start, $end])
            ->selectRaw('invoice_items.provider_id, SUM(invoice_items.amount) as total')
            ->groupBy('invoice_items.provider_id')
            ->get();

        $names = Provider::whereIn('id', $rows->pluck('provider_id')->filter())->pluck('name', 'id');

        return $rows
            ->map(fn ($r) => [
                'label' => $r->provider_id ? ($names[$r->provider_id] ?? 'Unknown') : 'Unattributed',
                'value' => round((float) $r->total, 2),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /** @return list<array{label: string, value: float}> */
    private function revenueByTreatment(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('treatment_plan_items', 'treatment_plan_items.id', '=', 'invoice_items.treatment_plan_item_id')
            ->where('invoices.status', '!=', 'void')
            ->whereBetween('invoices.issued_at', [$start, $end])
            ->selectRaw("COALESCE(treatment_plan_items.treatment, 'Ad-hoc / unlinked') as label, SUM(invoice_items.amount) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => round((float) $r->total, 2)]);

        return $this->topNWithOther($rows, 8);
    }

    /**
     * @param  Collection<int, array{label: string, value: float}>  $rows  sorted desc by value
     * @return list<array{label: string, value: float}>
     */
    private function topNWithOther(Collection $rows, int $n): array
    {
        if ($rows->count() <= $n) {
            return $rows->values()->all();
        }

        return $rows->take($n)
            ->push(['label' => 'Other', 'value' => round((float) $rows->slice($n)->sum('value'), 2)])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: string, 1: string}  $paidOnRange
     * @return list<array{label: string, value: float, count: int}>
     */
    private function methodMix(array $paidOnRange): array
    {
        $rows = Payment::query()
            ->whereBetween('paid_on', $paidOnRange)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        return collect(Payment::METHODS)->map(fn (string $m) => [
            'label' => $m,
            'value' => round((float) ($rows[$m]->total ?? 0), 2),
            'count' => (int) ($rows[$m]->count ?? 0),
        ])->all();
    }

    private function rangeLabel(string $range, Carbon $start, Carbon $end): string
    {
        return match ($range) {
            'this_month', 'last_month' => $start->format('F Y'),
            'ytd' => $start->format('Y'),
            default => $start->format('M j, Y').' – '.$end->format('M j, Y'),
        };
    }
}
