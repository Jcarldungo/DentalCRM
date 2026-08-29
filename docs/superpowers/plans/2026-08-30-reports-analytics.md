# Reports & Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only `/reports` page with a date-range selector and three sections — Revenue, Appointments, Patients — backed by SQL aggregates.

**Architecture:** One `Admin\ReportsController@index` (no model, no migration) that validates a `range` param, resolves it to `[start, end]` Carbon bounds, picks a time-series bucket granularity, runs a fixed set of `GROUP BY`/`SUM`/`COUNT` queries, and renders an Inertia `Reports/Index` page. Charts render client-side with a new lazy-chunked `recharts` dependency.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, Recharts 2, MariaDB (tests + dev).

**Spec:** `docs/superpowers/specs/2026-08-30-reports-analytics-design.md`

## Global Constraints

- **No roles.** Route behind `auth` only (not `verified`) — matches `/invoices`, `/workspace`. Whole report visible to any signed-in user.
- **Prices in `₱`.** Frontend reuses `formatPeso` from `resources/js/Pages/Patients/format.js`. Never reinvent it.
- **App timezone is UTC.** All range/bucket math is server-side Carbon in UTC.
- **Nothing transmitted.** No email/PDF/CSV in this slice.
- **Staff UI is light-theme only.** No dark-mode chart palette.
- **MariaDB-specific SQL is allowed** (`DATE_FORMAT`, `WEEKDAY`, `SUM(bool)`), tests and dev both run MariaDB.
- **Raw SQL fragments** passed to `selectRaw`/`orderByRaw` use only trusted literal column names this controller supplies — never request input.
- **Clean-codebase rules:** no `dd()`/`console.log`/`var_dump`, no unused imports, no commented-out code.
- **Inertia money assertions use INT literals** in `->where()` for whole-number values (`900`, not `900.0`) — `AssertableInertia` JSON-round-trips props and `json_encode(900.0)` decodes as `int`. Direct PHP `assertSame` on model helpers stays float. (Carried from the invoicing sub-project.)
- **Tests are flat:** `tests/Feature/ReportsTest.php`. `RefreshDatabase`. An `actingUser(): User` helper like `WorkspaceTest`.
- **Frontend page files must exist before their render tests run.** `resources/views/app.blade.php` does `@vite([... "resources/js/Pages/{$page['component']}.jsx"])`, so any `$this->get(route('reports.index'))` test throws `ViteException` unless `resources/js/Pages/Reports/Index.jsx` exists **and** `npm run build` has run. Task 1 creates a minimal real `Reports/Index.jsx` + builds; Task 5 replaces it with the full page.
- **Commits carry NO `Co-Authored-By` and NO `Claude-Session` trailer.** Short imperative subjects. One commit per task.

---

### Task 1: `ReportsController` skeleton — route, range resolution, bucket helpers, validation

**Files:**
- Create: `app/Http/Controllers/Admin/ReportsController.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Reports/Index.jsx` (minimal interim — Task 5 replaces)
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: `Appointment`, `Invoice`, `Payment`, `Provider`, `Patient` models (read-only).
- Produces:
  - Route `GET /reports` name `reports.index`.
  - `ReportsController::index(Request): Response` — validates `range` (nullable, one of `this_month`/`last_month`/`this_quarter`/`ytd`/`last_12_months`/`custom`), `start`/`end` (`required_if:range,custom`, `date`, `end after_or_equal:start`), rejects a custom span > 400 days with a 422 on `end`. Renders `Reports/Index` with prop `meta: {range, start (Y-m-d), end (Y-m-d), label, bucket ('day'|'week'|'month')}` and, in this task, empty section props `revenue: []`, `appointments: []`, `patients: []` (Tasks 2–4 fill them).
  - Private helpers later tasks reuse: `resolveRange(string, ?string, ?string): array{0: Carbon, 1: Carbon}`, `bucketFor(Carbon, Carbon): string`, `bucketKeys(Carbon, Carbon, string): list<string>`, `bucketExpr(string $column, string $bucket): string`, `fillSeries(list<string>, array<string,int|float>, int|float): list<array{bucket:string,value:int|float}>`, `rangeLabel(string, Carbon, Carbon): string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ReportsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_reports(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_it_renders_with_the_default_range(): void
    {
        $this->actingUser();

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('meta.range', 'this_month')
            ->where('meta.start', now()->startOfMonth()->toDateString())
            ->where('meta.end', now()->toDateString())
            ->where('meta.bucket', 'day')
            ->has('revenue')
            ->has('appointments')
            ->has('patients')
        );
    }

    public function test_custom_range_requires_both_dates(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom']))
            ->assertSessionHasErrors('start');
    }

    public function test_custom_range_rejects_end_before_start(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-03-01', 'end' => '2026-02-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_custom_range_rejects_a_span_over_400_days(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_bucket_granularity_follows_the_span(): void
    {
        $this->actingUser();

        // Deterministic custom spans — a calendar-relative preset can land
        // on a knife-edge (e.g. this_quarter on the 1st of a quarter).
        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-20']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'day'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'week'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-09-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'month'));
    }

    public function test_ytd_label_is_the_year(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'ytd']))
            ->assertInertia(fn ($page) => $page->where('meta.label', (string) now()->year));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: FAIL — route `reports.index` is undefined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, add `use App\Http\Controllers\Admin\ReportsController;` with the other `Admin` controller imports (alphabetical — after `PrescriptionController`, before `ProviderController`), and this line inside the `auth` middleware group, immediately after the `/workspace` route and before the `/invoices` routes:

```php
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
```

- [ ] **Step 4: Create the controller skeleton**

Create `app/Http/Controllers/Admin/ReportsController.php`:

```php
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
```

- [ ] **Step 5: Create the minimal interim page**

Create `resources/js/Pages/Reports/Index.jsx`:

```jsx
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Index({ meta }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Reports</h2>}>
            <Head title="Reports" />
            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 text-sm text-gray-500">
                {meta.label}
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 6: Build, then run the tests**

Run: `npm run build`
Expected: succeeds.

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: PASS — all ReportsTest cases green (7 in this task).

- [ ] **Step 7: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/ReportsController.php routes/web.php resources/js/Pages/Reports/Index.jsx tests/Feature/ReportsTest.php
git commit -m "Add Reports controller skeleton with range selector"
```

---

### Task 2: Revenue section queries

**Files:**
- Modify: `app/Http/Controllers/Admin/ReportsController.php`
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: Task 1's helpers; `Invoice` (`STATUSES`, `balance()`, `items`, `payments`, `discount_amount`, `issued_at`), `Payment` (`METHODS`, `amount`, `paid_on`, `method`), `Provider` (`name`), `invoice_items` / `treatment_plan_items` tables.
- Produces: `index()` `revenue` prop replaced with:
  ```
  {
    collected_total: float,
    invoiced_total: float,
    outstanding: { total: float, count: int },
    collected_trend: { bucket: string, series: [{bucket, value}] },
    by_provider: [{ label, value }]  // sorted desc, "Unattributed" for null provider
    by_treatment: [{ label, value }] // sorted desc, top 8 + "Other", "Ad-hoc / unlinked" for null link
    method_mix: [{ label, value, count }]  // every Payment::METHODS value, 0-filled
  }
  ```

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportsTest.php` (add `use` imports for `App\Models\{Appointment,Invoice,InvoiceItem,Patient,Payment,Provider,TreatmentPlanItem}` at the top as each task needs them):

```php
    public function test_collected_revenue_total_and_trend(): void
    {
        $this->actingUser();
        $start = now()->startOfMonth();

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 300, 'paid_on' => $start->clone()->addDays(2)->toDateString()]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 200, 'paid_on' => $start->clone()->addDays(2)->toDateString()]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 999, 'paid_on' => $start->clone()->subMonth()->toDateString()]);

        $response = $this->get(route('reports.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('revenue.collected_total', 500)
            ->where('revenue.collected_trend.bucket', 'day')
        );

        $page = $response->viewData('page')['props'];
        $series = $page['revenue']['collected_trend']['series'];
        $this->assertSame(now()->startOfMonth()->diffInDays(now()) + 1, count($series));
        $this->assertSame(500.0, array_sum(array_column($series, 'value')));
    }

    public function test_invoiced_total_is_net_of_discount_and_excludes_void(): void
    {
        $this->actingUser();

        $a = Invoice::factory()->issued()->create(['discount_amount' => 100, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $a->id, 'amount' => 1000]);

        $void = Invoice::factory()->void()->create(['issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 5000]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.invoiced_total', 900)
        );
    }

    public function test_outstanding_ar_matches_the_invoice_balance_basis(): void
    {
        $this->actingUser();

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 400, 'paid_on' => now()->toDateString()]);

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 9999]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.outstanding.total', 600)
            ->where('revenue.outstanding.count', 1)
        );
    }

    public function test_revenue_by_provider_splits_and_buckets_unattributed(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create(['name' => 'Dr. Reyes']);

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 700, 'provider_id' => $provider->id]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 300, 'provider_id' => null]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.by_provider', fn ($rows) => collect($rows)->firstWhere('label', 'Dr. Reyes')['value'] === 700
                && collect($rows)->firstWhere('label', 'Unattributed')['value'] === 300)
        );
    }

    public function test_revenue_by_treatment_buckets_unlinked(): void
    {
        $this->actingUser();
        $tpi = TreatmentPlanItem::factory()->create(['treatment' => 'Root Canal']);

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 800, 'treatment_plan_item_id' => $tpi->id]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 200, 'treatment_plan_item_id' => null]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.by_treatment', fn ($rows) => collect($rows)->firstWhere('label', 'Root Canal')['value'] === 800
                && collect($rows)->firstWhere('label', 'Ad-hoc / unlinked')['value'] === 200)
        );
    }

    public function test_payment_method_mix_zero_fills_all_methods(): void
    {
        $this->actingUser();
        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 250, 'method' => 'cash', 'paid_on' => now()->toDateString()]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->has('revenue.method_mix', 5)
            ->where('revenue.method_mix', fn ($rows) => collect($rows)->firstWhere('label', 'cash')['value'] === 250
                && collect($rows)->firstWhere('label', 'card')['value'] === 0)
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: FAIL — `revenue` is `[]`.

- [ ] **Step 3: Implement the revenue section**

In `ReportsController.php` add these imports: `use App\Models\Invoice;`, `use App\Models\Payment;`, `use App\Models\Provider;`, `use Illuminate\Support\Collection;`, `use Illuminate\Support\Facades\DB;`.

Replace `'revenue' => [],` in `index()` with `'revenue' => $this->revenue($start, $end, $bucket),` and add:

```php
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: PASS — all ReportsTest cases green (Task 1's plus 6 new).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ReportsController.php tests/Feature/ReportsTest.php
git commit -m "Add the Reports revenue section"
```

---

### Task 3: Appointments section queries

**Files:**
- Modify: `app/Http/Controllers/Admin/ReportsController.php`
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: Task 1 helpers; `Appointment` (`STATUSES`, `TYPES`, `status`, `type`, `start_time`, `provider_id`), `Provider`.
- Produces: `index()` `appointments` prop replaced with:
  ```
  {
    total: int,                        // all non-'requested' in range
    volume_trend: { bucket, series: [{bucket, value}] },
    status_breakdown: [{ label, value }]  // 7 statuses, 0-filled, 'requested' excluded
    rates: { completion: float, cancellation: float, no_show: float }  // 0..1, 4dp
    by_provider: [{ label, total, completed, no_show }]  // sorted desc by total
    by_type: [{ label, value }]         // every Appointment::TYPES value, 0-filled
  }
  ```

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportsTest.php`:

```php
    public function test_appointment_status_breakdown_excludes_requested_and_derives_rates(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(3)->setHour(10);

        foreach (['completed', 'completed', 'cancelled', 'no_show', 'scheduled'] as $status) {
            Appointment::factory()->create(['status' => $status, 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);
        }
        Appointment::factory()->create(['status' => 'requested', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.total', 5)
            ->where('appointments.rates.completion', 0.4)
            ->where('appointments.rates.cancellation', 0.2)
            ->where('appointments.rates.no_show', 0.2)
            ->where('appointments.status_breakdown', fn ($rows) => collect($rows)->firstWhere('label', 'completed')['value'] === 2
                && collect($rows)->firstWhere('label', 'requested') === null)
        );
    }

    public function test_appointments_by_provider_and_type(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(4)->setHour(11);
        $p = Provider::factory()->create(['name' => 'Dr. Lim']);

        Appointment::factory()->create(['provider_id' => $p->id, 'status' => 'completed', 'type' => 'cleaning', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);
        Appointment::factory()->create(['provider_id' => $p->id, 'status' => 'no_show', 'type' => 'checkup', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.by_provider', fn ($rows) => collect($rows)->firstWhere('label', 'Dr. Lim') === ['label' => 'Dr. Lim', 'total' => 2, 'completed' => 1, 'no_show' => 1])
            ->has('appointments.by_type', 4)
            ->where('appointments.by_type', fn ($rows) => collect($rows)->firstWhere('label', 'cleaning')['value'] === 1
                && collect($rows)->firstWhere('label', 'procedure')['value'] === 0)
        );
    }

    public function test_appointment_volume_trend_is_gap_filled(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(2)->setHour(9);
        Appointment::factory()->create(['status' => 'completed', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $page = $this->get(route('reports.index'))->viewData('page')['props'];
        $series = $page['appointments']['volume_trend']['series'];

        $this->assertSame(now()->startOfMonth()->diffInDays(now()) + 1, count($series));
        $this->assertSame(1, array_sum(array_column($series, 'value')));
    }

    public function test_rates_are_zero_when_there_are_no_appointments(): void
    {
        $this->actingUser();

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.total', 0)
            ->where('appointments.rates.completion', 0)
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: FAIL — `appointments` is `[]`.

- [ ] **Step 3: Implement the appointments section**

Add `use App\Models\Appointment;` to `ReportsController.php`. Replace `'appointments' => [],` with `'appointments' => $this->appointments($start, $end, $bucket),` and add:

```php
    private function appointments(Carbon $start, Carbon $end, string $bucket): array
    {
        $base = fn () => Appointment::query()
            ->where('status', '!=', 'requested')
            ->whereBetween('start_time', [$start, $end]);

        $keys = $this->bucketKeys($start, $end, $bucket);
        $volumeByBucket = $base()
            ->selectRaw($this->bucketExpr('start_time', $bucket).' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v) => (int) $v)
            ->all();

        $statusRows = $base()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = ['scheduled', 'checked_in', 'in_treatment', 'completed', 'cancelled', 'no_show', 'declined'];
        $statusBreakdown = array_map(
            fn (string $s) => ['label' => $s, 'value' => (int) ($statusRows[$s] ?? 0)],
            $statuses,
        );

        $total = array_sum(array_column($statusBreakdown, 'value'));
        $rate = fn (int $n) => $total > 0 ? round($n / $total, 4) : 0.0;
        $cancelled = (int) ($statusRows['cancelled'] ?? 0) + (int) ($statusRows['declined'] ?? 0);

        $providerRows = $base()
            ->selectRaw("provider_id, COUNT(*) as total, SUM(status = 'completed') as completed, SUM(status = 'no_show') as no_show")
            ->groupBy('provider_id')
            ->get();
        $names = Provider::whereIn('id', $providerRows->pluck('provider_id')->filter())->pluck('name', 'id');
        $byProvider = $providerRows
            ->map(fn ($r) => [
                'label' => $r->provider_id ? ($names[$r->provider_id] ?? 'Unknown') : 'Unassigned',
                'total' => (int) $r->total,
                'completed' => (int) $r->completed,
                'no_show' => (int) $r->no_show,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $typeRows = $base()->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');
        $byType = array_map(
            fn (string $t) => ['label' => $t, 'value' => (int) ($typeRows[$t] ?? 0)],
            Appointment::TYPES,
        );

        return [
            'total' => $total,
            'volume_trend' => ['bucket' => $bucket, 'series' => $this->fillSeries($keys, $volumeByBucket, 0)],
            'status_breakdown' => $statusBreakdown,
            'rates' => [
                'completion' => $rate((int) ($statusRows['completed'] ?? 0)),
                'cancellation' => $rate($cancelled),
                'no_show' => $rate((int) ($statusRows['no_show'] ?? 0)),
            ],
            'by_provider' => $byProvider,
            'by_type' => $byType,
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: PASS — all ReportsTest cases green (plus 4 new).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ReportsController.php tests/Feature/ReportsTest.php
git commit -m "Add the Reports appointments section"
```

---

### Task 4: Patients section queries

**Files:**
- Modify: `app/Http/Controllers/Admin/ReportsController.php`
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: Task 1 helpers; `Patient` (`created_at`, `full_name`), `Appointment` (`status`, `start_time`, `patient_id`).
- Produces: `index()` `patients` prop replaced with:
  ```
  {
    new_total: int,
    new_trend: { bucket, series: [{bucket, value}] },
    seen: { returning: int, first_visit: int },
    no_show_patients: { count: int, list: [{id, name, no_show_count}] }  // list capped at 20, sorted desc
  }
  ```

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportsTest.php`:

```php
    public function test_new_patients_trend_and_total(): void
    {
        $this->actingUser();
        Patient::factory()->create(['created_at' => now()->startOfMonth()->addDay()]);
        Patient::factory()->create(['created_at' => now()->startOfMonth()->addDays(2)]);
        Patient::factory()->create(['created_at' => now()->startOfMonth()->subMonth()]);

        $response = $this->get(route('reports.index'));
        $response->assertInertia(fn ($page) => $page->where('patients.new_total', 2));

        $series = $response->viewData('page')['props']['patients']['new_trend']['series'];
        $this->assertSame(2, array_sum(array_column($series, 'value')));
    }

    public function test_returning_versus_first_visit(): void
    {
        $this->actingUser();
        $inRange = now()->startOfMonth()->addDays(3)->setHour(10);

        $returning = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $returning->id, 'status' => 'completed', 'start_time' => now()->subMonths(3), 'end_time' => now()->subMonths(3)->addMinutes(30)]);
        Appointment::factory()->create(['patient_id' => $returning->id, 'status' => 'completed', 'start_time' => $inRange, 'end_time' => $inRange->clone()->addMinutes(30)]);

        $firstTimer = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $firstTimer->id, 'status' => 'completed', 'start_time' => $inRange, 'end_time' => $inRange->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('patients.seen.returning', 1)
            ->where('patients.seen.first_visit', 1)
        );
    }

    public function test_no_show_patients_list_is_capped_and_ordered(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(5)->setHour(9);

        $twice = Patient::factory()->create(['first_name' => 'Nora', 'last_name' => 'Kaye']);
        Appointment::factory()->count(2)->create(['patient_id' => $twice->id, 'status' => 'no_show', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $once = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $once->id, 'status' => 'no_show', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('patients.no_show_patients.count', 2)
            ->where('patients.no_show_patients.list.0.name', 'Nora Kaye')
            ->where('patients.no_show_patients.list.0.no_show_count', 2)
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: FAIL — `patients` is `[]`.

- [ ] **Step 3: Implement the patients section**

Add `use App\Models\Patient;` to `ReportsController.php`. Replace `'patients' => [],` with `'patients' => $this->patients($start, $end, $bucket),` and add:

```php
    private function patients(Carbon $start, Carbon $end, string $bucket): array
    {
        $keys = $this->bucketKeys($start, $end, $bucket);
        $newByBucket = Patient::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw($this->bucketExpr('created_at', $bucket).' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v) => (int) $v)
            ->all();

        $inRangePatientIds = Appointment::query()
            ->where('status', 'completed')
            ->whereBetween('start_time', [$start, $end])
            ->distinct()
            ->pluck('patient_id');

        $returning = Appointment::query()
            ->where('status', 'completed')
            ->where('start_time', '<', $start)
            ->whereIn('patient_id', $inRangePatientIds)
            ->distinct()
            ->pluck('patient_id')
            ->count();

        $noShowRows = Appointment::query()
            ->where('status', 'no_show')
            ->whereBetween('start_time', [$start, $end])
            ->selectRaw('patient_id, COUNT(*) as no_show_count')
            ->groupBy('patient_id')
            ->orderByDesc('no_show_count')
            ->get();
        $noShowNames = Patient::whereIn('id', $noShowRows->pluck('patient_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return [
            'new_total' => array_sum($newByBucket),
            'new_trend' => ['bucket' => $bucket, 'series' => $this->fillSeries($keys, $newByBucket, 0)],
            'seen' => [
                'returning' => $returning,
                'first_visit' => $inRangePatientIds->count() - $returning,
            ],
            'no_show_patients' => [
                'count' => $noShowRows->count(),
                'list' => $noShowRows->take(20)->map(fn ($r) => [
                    'id' => $r->patient_id,
                    'name' => ($noShowNames[$r->patient_id] ?? null)?->full_name ?? 'Unknown',
                    'no_show_count' => (int) $r->no_show_count,
                ])->values()->all(),
            ],
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ReportsTest`
Expected: PASS — all ReportsTest cases green (plus 3 new).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ReportsController.php tests/Feature/ReportsTest.php
git commit -m "Add the Reports patients section"
```

---

### Task 5: Frontend — Recharts, chart components, and the full `Reports/Index` page

**Files:**
- Modify: `package.json` (add `recharts`), `package-lock.json` (from `npm install`)
- Create: `resources/js/Pages/Reports/charts.jsx`
- Create: `resources/js/Pages/Reports/RangePicker.jsx`
- Create: `resources/js/Pages/Reports/components.jsx`
- Overwrite: `resources/js/Pages/Reports/Index.jsx` (was the Task 1 stub)

**Interfaces:**
- Consumes: `meta` / `revenue` / `appointments` / `patients` props from Tasks 1–4; `formatPeso` / `formatDate` from `@/Pages/Patients/format`; routes `reports.index`, `patients.show`.
- Produces: the finished page. No backend change. No PHPUnit test (view-only, matches `BillingTab`/`PrescriptionsTab` precedent) — verified by `npm run build` + the full suite (the Task 1 render tests now exercise the real page).

**Before writing any chart code, load the `dataviz` skill** and follow it: form picked first (area for time-series, horizontal bars for by-category magnitude, stat tiles for headline numbers), hover tooltips on by default, recessive grid/axes, a legend only when ≥ 2 series (none here are multi-series, so no legend and no categorical-palette validation needed — but if you introduce a 2-series chart, run `scripts/validate_palette.js`). Single data hue: `#2563eb` (matches the app's link blue) on the white card surface.

- [ ] **Step 1: Add Recharts**

Run: `npm install recharts@^2.12.0`
Expected: `recharts` appears in `package.json` `dependencies` and `package-lock.json` updates.

- [ ] **Step 2: Create the chart wrapper `charts.jsx`**

Create `resources/js/Pages/Reports/charts.jsx` — the single `recharts` import site:

```jsx
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatPeso } from '@/Pages/Patients/format';

const HUE = '#2563eb';
const GRID = '#e5e7eb';
const AXIS = '#9ca3af';

function fmtCount(n) {
    return Number(n).toLocaleString();
}

function fmtBucket(key, bucket) {
    const d = new Date(key + 'T00:00:00');
    if (bucket === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function TrendChart({ series, bucket, valueFormat }) {
    const fmt = valueFormat === 'peso' ? formatPeso : fmtCount;
    const hasData = series.some((p) => p.value > 0);

    if (!hasData) {
        return <p className="py-8 text-center text-sm text-gray-400">No data for this period.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis
                    dataKey="bucket"
                    tickFormatter={(k) => fmtBucket(k, bucket)}
                    tick={{ fontSize: 11, fill: AXIS }}
                    tickLine={false}
                    axisLine={{ stroke: GRID }}
                    minTickGap={24}
                />
                <YAxis
                    tickFormatter={(v) => (valueFormat === 'peso' ? `₱${Number(v).toLocaleString()}` : fmtCount(v))}
                    tick={{ fontSize: 11, fill: AXIS }}
                    tickLine={false}
                    axisLine={false}
                    width={64}
                />
                <Tooltip
                    formatter={(v) => [fmt(v), valueFormat === 'peso' ? 'Collected' : 'Count']}
                    labelFormatter={(k) => fmtBucket(k, bucket)}
                />
                <Area type="monotone" dataKey="value" stroke={HUE} strokeWidth={2} fill={HUE} fillOpacity={0.12} />
            </AreaChart>
        </ResponsiveContainer>
    );
}

export function MiniBars({ rows, valueFormat }) {
    const fmt = valueFormat === 'peso' ? formatPeso : fmtCount;
    if (rows.length === 0 || rows.every((r) => r.value === 0)) {
        return <p className="py-6 text-center text-sm text-gray-400">No data for this period.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 40)}>
            <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 48, bottom: 4, left: 8 }}>
                <XAxis type="number" hide />
                <YAxis
                    type="category"
                    dataKey="label"
                    width={130}
                    tick={{ fontSize: 12, fill: '#374151' }}
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip formatter={(v) => [fmt(v), 'Total']} cursor={{ fill: '#f3f4f6' }} />
                <Bar dataKey="value" fill={HUE} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}
```

- [ ] **Step 3: Create the display primitives `components.jsx`**

Create `resources/js/Pages/Reports/components.jsx`:

```jsx
import { Link } from '@inertiajs/react';

export function Section({ title, children }) {
    return (
        <section className="space-y-4">
            <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
            {children}
        </section>
    );
}

export function StatTile({ label, value, sub }) {
    return (
        <div className="rounded border bg-white p-4 shadow-sm">
            <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">{value}</div>
            {sub && <div className="mt-0.5 text-xs text-gray-500">{sub}</div>}
        </div>
    );
}

export function Card({ title, note, children }) {
    return (
        <div className="rounded border bg-white p-4 shadow-sm">
            <div className="mb-2 flex items-baseline justify-between">
                <h4 className="text-sm font-medium text-gray-700">{title}</h4>
                {note && <span className="text-xs text-gray-400">{note}</span>}
            </div>
            {children}
        </div>
    );
}

export function RateBar({ label, value }) {
    const pct = Math.round(value * 1000) / 10;
    return (
        <div>
            <div className="flex justify-between text-sm">
                <span className="text-gray-600">{label}</span>
                <span className="font-medium text-gray-900">{pct}%</span>
            </div>
            <div className="mt-1 h-1.5 rounded bg-gray-100">
                <div className="h-1.5 rounded bg-blue-600" style={{ width: `${Math.min(pct, 100)}%` }} />
            </div>
        </div>
    );
}

export function NoShowList({ list }) {
    if (list.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">No no-shows this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <tbody>
                {list.map((p) => (
                    <tr key={p.id} className="border-b last:border-0">
                        <td className="py-2">
                            <Link href={route('patients.show', p.id)} className="text-blue-600">
                                {p.name}
                            </Link>
                        </td>
                        <td className="py-2 text-right text-gray-500">{p.no_show_count}×</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function ProviderTable({ rows }) {
    if (rows.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">No data for this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th className="py-1">Provider</th>
                    <th className="py-1 text-right">Total</th>
                    <th className="py-1 text-right">Completed</th>
                    <th className="py-1 text-right">No-show</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.label} className="border-b last:border-0">
                        <td className="py-2">{r.label}</td>
                        <td className="py-2 text-right">{r.total}</td>
                        <td className="py-2 text-right">{r.completed}</td>
                        <td className="py-2 text-right">{r.no_show}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
```

- [ ] **Step 4: Create `RangePicker.jsx`**

```jsx
import { useState } from 'react';
import { router } from '@inertiajs/react';

const PRESETS = [
    ['this_month', 'This month'],
    ['last_month', 'Last month'],
    ['this_quarter', 'This quarter'],
    ['ytd', 'Year to date'],
    ['last_12_months', 'Last 12 months'],
    ['custom', 'Custom'],
];

export default function RangePicker({ meta }) {
    const [start, setStart] = useState(meta.start);
    const [end, setEnd] = useState(meta.end);

    function go(params) {
        router.get(route('reports.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function pick(range) {
        if (range === 'custom') {
            go({ range: 'custom', start, end });
        } else {
            go({ range });
        }
    }

    return (
        <div className="sticky top-0 z-10 -mx-4 mb-6 border-b bg-gray-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded sm:border">
            <div className="flex flex-wrap items-center gap-2">
                {PRESETS.map(([value, text]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => pick(value)}
                        className={`rounded border px-3 py-1 text-sm ${
                            meta.range === value
                                ? 'border-gray-900 bg-gray-900 text-white'
                                : 'border-gray-300 text-gray-600'
                        }`}
                    >
                        {text}
                    </button>
                ))}
                <span className="ml-1 text-sm text-gray-500">{meta.label}</span>
            </div>

            {meta.range === 'custom' && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="text-xs text-gray-500">
                        From
                        <input
                            type="date"
                            value={start}
                            onChange={(e) => setStart(e.target.value)}
                            className="mt-1 block rounded border px-2 py-1 text-sm"
                        />
                    </label>
                    <label className="text-xs text-gray-500">
                        To
                        <input
                            type="date"
                            value={end}
                            onChange={(e) => setEnd(e.target.value)}
                            className="mt-1 block rounded border px-2 py-1 text-sm"
                        />
                    </label>
                    <button
                        type="button"
                        onClick={() => go({ range: 'custom', start, end })}
                        className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                    >
                        Apply
                    </button>
                </div>
            )}
        </div>
    );
}
```

- [ ] **Step 5: Overwrite `Index.jsx` with the full page**

```jsx
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatPeso } from '@/Pages/Patients/format';
import RangePicker from './RangePicker';
import { TrendChart, MiniBars } from './charts';
import { Card, NoShowList, ProviderTable, RateBar, Section, StatTile } from './components';

function pct(n) {
    return `${Math.round(n * 1000) / 10}%`;
}

export default function Index({ meta, revenue, appointments, patients }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Reports</h2>}>
            <Head title="Reports" />

            <div className="py-6 max-w-5xl mx-auto sm:px-6 lg:px-8">
                <RangePicker meta={meta} />

                <div className="space-y-10">
                    <Section title="Revenue">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile label="Collected" value={formatPeso(revenue.collected_total)} />
                            <StatTile label="Invoiced" value={formatPeso(revenue.invoiced_total)} sub="net of discount" />
                            <StatTile
                                label="Outstanding"
                                value={formatPeso(revenue.outstanding.total)}
                                sub={`${revenue.outstanding.count} invoice${revenue.outstanding.count === 1 ? '' : 's'}`}
                            />
                        </div>

                        <Card title="Collected over time">
                            <TrendChart
                                series={revenue.collected_trend.series}
                                bucket={revenue.collected_trend.bucket}
                                valueFormat="peso"
                            />
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2">
                            <Card title="By provider" note="invoiced, gross of discount">
                                <MiniBars rows={revenue.by_provider} valueFormat="peso" />
                            </Card>
                            <Card title="By treatment" note="invoiced">
                                <MiniBars rows={revenue.by_treatment} valueFormat="peso" />
                            </Card>
                        </div>

                        <Card title="Payment method mix">
                            <MiniBars
                                rows={revenue.method_mix.map((m) => ({
                                    label: m.label.replace('_', ' '),
                                    value: m.value,
                                }))}
                                valueFormat="peso"
                            />
                        </Card>
                    </Section>

                    <Section title="Appointments">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile label="Total" value={appointments.total} />
                            <StatTile label="Completion" value={pct(appointments.rates.completion)} />
                            <StatTile label="Cancellation" value={pct(appointments.rates.cancellation)} />
                            <StatTile label="No-show" value={pct(appointments.rates.no_show)} />
                        </div>

                        <Card title="Volume over time">
                            <TrendChart
                                series={appointments.volume_trend.series}
                                bucket={appointments.volume_trend.bucket}
                                valueFormat="count"
                            />
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2">
                            <Card title="By provider">
                                <ProviderTable rows={appointments.by_provider} />
                            </Card>
                            <Card title="By type">
                                <MiniBars
                                    rows={appointments.by_type.map((t) => ({ label: t.label, value: t.value }))}
                                    valueFormat="count"
                                />
                            </Card>
                        </div>

                        <Card title="Rates">
                            <div className="space-y-3">
                                <RateBar label="Completed" value={appointments.rates.completion} />
                                <RateBar label="Cancelled / declined" value={appointments.rates.cancellation} />
                                <RateBar label="No-show" value={appointments.rates.no_show} />
                            </div>
                        </Card>
                    </Section>

                    <Section title="Patients">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile label="New patients" value={patients.new_total} />
                            <StatTile label="Returning" value={patients.seen.returning} />
                            <StatTile label="First visit" value={patients.seen.first_visit} />
                            <StatTile label="No-show patients" value={patients.no_show_patients.count} />
                        </div>

                        <Card title="New patients over time">
                            <TrendChart
                                series={patients.new_trend.series}
                                bucket={patients.new_trend.bucket}
                                valueFormat="count"
                            />
                        </Card>

                        <Card title="No-show patients">
                            <NoShowList list={patients.no_show_patients.list} />
                        </Card>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: succeeds; a `recharts`-containing chunk appears (e.g. `charts-*.js` / a vendor chunk), not in `app-*.js`. No unresolved-import or "X is not defined" errors.

- [ ] **Step 7: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass — the Task 1 `Reports/Index` render tests still pass against the real page.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json resources/js/Pages/Reports/
git commit -m "Add the Reports page with Recharts trend and breakdown charts"
```

---

### Task 6: DemoSeeder data, nav link, and docs

**Files:**
- Modify: `database/seeders/DemoSeeder.php`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: `Invoice` / `InvoiceItem` / `Payment` / `TreatmentPlanItem` / `Appointment` / `Patient` / `Provider` factories.
- Produces: a `/reports` page that looks populated on `db:seed --class=DemoSeeder`; a "Reports" nav link; updated docs. No test (seeder + nav + docs).

- [ ] **Step 1: Extend `DemoSeeder`**

In `database/seeders/DemoSeeder.php`, add `use App\Models\Invoice;`, `use App\Models\InvoiceItem;`, `use App\Models\Payment;`, `use App\Models\TreatmentPlanItem;`, `use App\Models\User;` to the imports. At the end of `run()`, after the existing calendar-population block, append:

```php
        // --- Reporting fixtures: ~120 days of billed activity so /reports
        //     is populated on a fresh seed. Additive; nothing above changes.
        $staff = User::factory()->create();
        $allPatients = Patient::all();
        $types = ['checkup', 'cleaning', 'procedure', 'other'];
        $methods = ['cash', 'card', 'bank_transfer'];

        foreach (range(1, 30) as $i) {
            $day = Carbon::now()->subDays(rand(3, 118))->setTime(rand(9, 16), [0, 30][rand(0, 1)]);
            $status = ['completed', 'completed', 'completed', 'cancelled', 'no_show', 'scheduled'][rand(0, 5)];

            Appointment::factory()->create([
                'patient_id' => $allPatients->random()->id,
                'provider_id' => $providers->random()->id,
                'type' => $types[array_rand($types)],
                'status' => $status,
                'start_time' => $day,
                'end_time' => (clone $day)->addMinutes(30),
            ]);
        }

        foreach (range(1, 15) as $i) {
            $patient = $allPatients->random();
            $provider = $providers->random();
            $issuedAt = Carbon::now()->subDays(rand(3, 115));

            $tpi = rand(0, 1) === 1
                ? TreatmentPlanItem::factory()->create([
                    'patient_id' => $patient->id,
                    'provider_id' => $provider->id,
                    'treatment' => ['Dental Cleaning', 'Composite Filling', 'Root Canal Treatment', 'Crown'][rand(0, 3)],
                ])
                : null;

            $invoice = Invoice::factory()->issued()->create([
                'patient_id' => $patient->id,
                'discount_amount' => [0, 0, 250][rand(0, 2)],
                'issued_at' => $issuedAt,
                'created_by' => $staff->id,
            ]);

            $lineTotal = 0;
            foreach (range(1, rand(1, 3)) as $line) {
                $amount = rand(6, 40) * 100;
                $lineTotal += $amount;
                InvoiceItem::factory()->create([
                    'invoice_id' => $invoice->id,
                    'treatment_plan_item_id' => $line === 1 ? $tpi?->id : null,
                    'provider_id' => $line === 1 ? ($tpi?->provider_id ?? $provider->id) : null,
                    'amount' => $amount,
                ]);
            }

            // 0 = unpaid, 1 = partial, 2 = paid in full
            $payLevel = rand(0, 2);
            if ($payLevel > 0) {
                $pay = $payLevel === 2 ? $lineTotal - (int) $invoice->discount_amount : (int) ($lineTotal * 0.5);
                Payment::factory()->create([
                    'invoice_id' => $invoice->id,
                    'amount' => $pay,
                    'method' => $methods[array_rand($methods)],
                    'paid_on' => $issuedAt->clone()->addDays(rand(0, 20))->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        Invoice::factory()->count(2)->create(['patient_id' => $allPatients->random()->id]);
        Invoice::factory()->void()->create(['patient_id' => $allPatients->random()->id]);
```

If the `Invoice`/`Payment` factories do not set `created_by`, the explicit `'created_by' => $staff->id` above covers it; if they require other fields, fill them from the factory defaults — do not change the factories.

- [ ] **Step 2: Verify the seeder runs**

Run: `"$HOME/.config/herd/bin/php.bat" artisan migrate:fresh --seed --seeder=DemoSeeder`
Expected: completes with no error. (This runs against the dev DB, not the test DB.)

- [ ] **Step 3: Add the nav link**

In `resources/js/Layouts/AuthenticatedLayout.jsx`, in the desktop nav immediately after the `invoices.index` `<NavLink>` (the "Billing" link) and before `inquiries.index`:

```jsx
                                <NavLink
                                    href={route('reports.index')}
                                    active={route().current('reports.*')}
                                >
                                    Reports
                                </NavLink>
```

And in the responsive nav, immediately after the `invoices.index` `<ResponsiveNavLink>` and before `inquiries.index`:

```jsx
                        <ResponsiveNavLink
                            href={route('reports.index')}
                            active={route().current('reports.*')}
                        >
                            Reports
                        </ResponsiveNavLink>
```

- [ ] **Step 4: Update `CLAUDE.md`**

**(a)** Under "Planning workflow" → "Shipped so far", immediately after the "Phase 7, sub-project 1" bullet, add:

```markdown
- **Phase 7, sub-project 2** — reports & analytics, specced at
  `docs/superpowers/specs/2026-08-30-reports-analytics-design.md` — a
  read-only `Admin\ReportsController@index` (`GET /reports`, no model or
  migration) rendering `Reports/Index`: a date-range selector
  (`this_month` default / `last_month` / `this_quarter` / `ytd` /
  `last_12_months` / `custom` with a 400-day cap) resolved server-side to
  `[start, end]` UTC bounds, a time-series bucket granularity
  (day ≤ 31d, week ≤ 180d, else month) applied to every trend and
  gap-filled, and three sections of SQL aggregates — Revenue (collected
  vs invoiced vs outstanding-A/R, collected-over-time, by-provider and
  by-treatment on the invoiced basis, payment-method mix), Appointments
  (volume, status breakdown excluding `requested`, completion/
  cancellation/no-show rates, by-provider, by-type), Patients (new over
  time, returning vs first-visit, no-show patients). Charts render with
  a new lazy-chunked `recharts` dependency (area for trends, horizontal
  bars for breakdowns; stat tiles for headline numbers). Behind `auth`
  only — every staff member sees the whole report. Treatments-section
  analytics, recall-adherence, and any export (CSV/PDF) are deferred.
```

**(b)** Under "Known gaps", append:

```markdown
- Every `/reports` query is unbounded and unpaginated; by-provider /
  by-treatment load all matching `invoice_items` for the range, and
  outstanding-A/R re-derives `balance()` by loading every issued invoice
  with its items and payments (same accepted O(n) pattern as the
  dashboard tile). Fine at demo scale; a multi-year dataset would want
  summary tables or date-partitioned indexes on `payments.paid_on`,
  `invoices.issued_at`, `appointments.start_time`.
- Reports "invoiced revenue by provider" is gross of invoice-level
  discount (a discount is not allocable to one line/provider); the
  invoiced *total* is net. Both are labelled in the UI.
- Reports date ranges are UTC boundaries — no timezone handling, so a
  non-UTC clinic sees report days roll over off-midnight local. Matches
  the rest of the app.
```

- [ ] **Step 5: Build and run the full suite**

Run: `npm run build`
Expected: succeeds.

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/DemoSeeder.php resources/js/Layouts/AuthenticatedLayout.jsx CLAUDE.md
git commit -m "Seed reporting demo data and add the Reports nav link"
```

---

## Self-Review

**1. Spec coverage:**

| Spec section | Task |
|---|---|
| `GET /reports` → `ReportsController@index`, no model/migration, behind `auth` | Task 1 |
| `range` param, six values, `custom` needs `start`/`end`, 400-day cap, 422s | Task 1 (tests + impl) |
| `resolveRange` → `[start, end]` Carbon; `label`; `bucket` (day ≤31 / week ≤180 / month) | Task 1 |
| Gap-filled time-series; `bucket` echoed in response | Task 1 (`bucketKeys`/`fillSeries`), used by Tasks 2–4 |
| MariaDB bucket expressions; raw SQL only from trusted literals | Task 1 (`bucketExpr` + docblock) |
| Collected total / trend (cash basis, `payments.paid_on`) | Task 2 |
| Invoiced total (non-void, `issued_at` in range, net of discount) | Task 2 |
| Outstanding A/R snapshot matching `DashboardController` basis | Task 2 |
| Revenue by provider (invoiced, `invoice_items.provider_id`, "Unattributed" bucket) | Task 2 |
| Revenue by treatment (LEFT JOIN TPI, "Ad-hoc / unlinked", top 8 + Other) | Task 2 |
| Payment method mix (every `Payment::METHODS`, 0-filled) | Task 2 |
| Appointment volume / status breakdown excluding `requested` / derived rates | Task 3 |
| Appointments by provider (total/completed/no-show) and by type (0-filled `TYPES`) | Task 3 |
| New patients trend + total | Task 4 |
| Returning vs first-visit (prior completed appointment before range start) | Task 4 |
| No-show patients (count + capped, ordered list linking to `/patients/{id}`) | Task 4 |
| `Reports/Index` page: sticky range picker, three stacked sections, empty states | Tasks 1 (stub) + 5 (full) |
| Components: RangePicker, StatTile, TrendChart (area), BreakdownBars (h-bars), rate tiles, no-show list | Task 5 |
| Recharts dependency, single import site, lazy page chunk | Task 5 |
| `dataviz` skill applied (form first, hover on, recessive axes, single hue) | Task 5 (explicit instruction) |
| DemoSeeder populated (~120 days appts + ~15 issued invoices + payments + draft/void) | Task 6 |
| "Reports" nav link (desktop + responsive) after "Billing" | Task 6 |
| CLAUDE.md shipped-so-far bullet + 3 known-gaps entries | Task 6 |
| Deferred: Treatments section, recall adherence, export, accrual toggle, per-provider scoping, period comparison | Honoured — no task builds them |

**2. Placeholder scan:** No "TBD" / "handle edge cases" / "similar to Task N". Every code step carries literal code. Task 5's "load the `dataviz` skill" is a real instruction with concrete follow-through (single hue `#2563eb`, form choices named). Task 6's seeder note about factory fields is a contingency, not a placeholder — the primary code is complete.

**3. Type consistency:**
- `meta` shape (`range`, `start`, `end`, `label`, `bucket`) defined in Task 1, consumed in Task 5's `RangePicker` and `Index`. ✓
- Trend shape `{ bucket, series: [{bucket, value}] }` produced identically by `revenue.collected_trend` (T2), `appointments.volume_trend` (T3), `patients.new_trend` (T4); consumed by `TrendChart` (T5). ✓
- Breakdown row shape `{label, value}` from `revenue.by_provider`/`by_treatment` (T2), `appointments.by_type` (T3); `MiniBars` reads `label`/`value` (T5). `method_mix` adds `count` (unused by `MiniBars`, mapped away in `Index`). ✓
- `appointments.by_provider` uses `{label, total, completed, no_show}` — consumed by `ProviderTable` (T5), not `MiniBars`. ✓
- `patients.no_show_patients.list` items `{id, name, no_show_count}` — consumed by `NoShowList` (T5). ✓
- `rates` are `0..1` floats server-side; `pct()` / `RateBar` multiply by 100 client-side. ✓
- Helper names: `bucketKeys`, `bucketExpr`, `fillSeries`, `resolveRange`, `bucketFor`, `rangeLabel` defined in Task 1; Tasks 2–4 call `bucketKeys`/`bucketExpr`/`fillSeries` with the exact signatures. ✓
- Route name `reports.index` — Task 1 registration, Task 5 `router.get` + `route()` calls, Task 6 nav links, every test. ✓
- `formatPeso` imported from `@/Pages/Patients/format` in `charts.jsx` and `Index.jsx` (T5). ✓

**4. Prior-art consistency:** Controller shape mirrors `WorkspaceController` (validate → resolve → aggregate → `Inertia::render`). Interim-page-stub + `npm run build` pattern matches the invoicing sub-project's Task 2/5. Int-literal Inertia money assertions match the invoicing correction. Nav-link insertion mirrors the "Billing" link added in invoicing Task 8. Per-tab/per-section component split matches `BillingTab.jsx`/`PrescriptionsTab.jsx`.
