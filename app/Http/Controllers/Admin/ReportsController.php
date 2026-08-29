<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonPeriod;
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
            'revenue' => [],
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

    private function rangeLabel(string $range, Carbon $start, Carbon $end): string
    {
        return match ($range) {
            'this_month', 'last_month' => $start->format('F Y'),
            'ytd' => $start->format('Y'),
            default => $start->format('M j, Y').' – '.$end->format('M j, Y'),
        };
    }
}
