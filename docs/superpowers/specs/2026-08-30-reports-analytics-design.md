# Reports & Analytics — Design

**Phase 7, sub-project 2.** Status: approved for planning.

## Goal

Give front-desk staff and clinic management a single `/reports` page that
answers the money and operations questions the platform can already back
with data: *how much came in, from whom, and how is the schedule
performing*. This closes the "revenue reporting" item that
`docs/superpowers/specs/2026-08-29-invoicing-payments-design.md`
explicitly deferred to this sub-project.

## Architecture

One read-only `Admin\ReportsController@index` (`GET /reports`, name
`reports.index`) rendering an Inertia page `Reports/Index`. The
controller validates a date-range selector, runs a fixed set of
SQL aggregate queries (`GROUP BY` / `SUM` / `COUNT`, `whereBetween`),
and hands the page plain arrays. No model, no migration, no write path —
same shape as `Admin\WorkspaceController`.

Charts render client-side with **Recharts** (new dependency), lazy-loaded
so the library is absent from every other page's bundle.

## Global constraints (inherited)

- **No roles.** Every authenticated user is an equal front-desk staff
  member, so the whole report is visible to any signed-in user. Route
  sits behind `auth` (not `verified` — matches `/invoices`, `/workspace`).
  No per-provider "my numbers only" scoping (that needs RBAC, deferred
  platform-wide).
- **Prices in Philippine pesos (`₱`).** Reuse `formatPeso` from
  `resources/js/Pages/Patients/format.js`; do not reinvent.
- **App timezone is UTC** (`config/app.php` default). All range math and
  date bucketing is Carbon in UTC, server-side — consistent with the rest
  of the app, which does no timezone juggling.
- **Nothing is transmitted.** No scheduled email, no PDF, no CSV export in
  this slice (see Out of scope).
- **Clean-codebase rules** (no `dd()`/`console.log`, no unused imports, no
  commented-out code) as per `CLAUDE.md`.
- **Staff UI is light-theme only** (`AuthenticatedLayout.jsx`). Charts are
  designed for the light surface; no dark-mode chart palette.

## Date-range selector

One query parameter, `range`, mirroring how `WorkspaceController` takes a
single meaningful `date` param.

| `range` value    | Meaning (all inclusive, UTC)                       |
|------------------|---------------------------------------------------|
| `this_month`     | 1st of current month → today. **Default.**         |
| `last_month`     | full previous calendar month                       |
| `this_quarter`   | 1st of current quarter → today                     |
| `ytd`            | Jan 1 of current year → today                      |
| `last_12_months` | today minus 12 months → today                     |
| `custom`         | requires `start` and `end` (`Y-m-d`), `start ≤ end`|

Validation: `range` in the six values above (nullable → `this_month`).
When `range=custom`: `start` and `end` are `required|date`, `end` after or
equal to `start`, and the span is capped at **400 days** (`end` no more
than 400 days after `start`) to bound query cost. Any validation failure
returns the standard Inertia 422 with errors on `range` / `start` / `end`.

The controller resolves `range` to a concrete `[$start, $end]` pair of
Carbon instances (`$start->startOfDay()`, `$end->endOfDay()`) and echoes
back to the page: `range` (the key), `start` / `end` (`Y-m-d` strings),
and `label` (a human string like "August 2026" or "Jul 12 – Aug 30, 2026").

### Time-series bucket granularity

The controller picks one bucket size for every time-series in the
response, from the span in days (`$start->diffInDays($end)`):

- `≤ 31` days → **daily** (`DATE(col)`)
- `≤ 180` days → **weekly** (ISO week: `YEARWEEK(col, 3)`, label = that
  week's Monday as `Y-m-d`)
- else → **monthly** (`DATE_FORMAT(col, '%Y-%m-01')`)

Every time-series in the response is **gap-filled**: the controller
generates the full ordered list of buckets in `[$start, $end]` and
left-joins the query results onto it, so a week with no revenue is a
`0`, not a missing point. The response carries `bucket` (`"day"` /
`"week"` / `"month"`) so the page can format the axis.

MariaDB-specific date functions are fine — tests and dev both run
MariaDB (`CLAUDE.md`).

## Data & queries

All money sums are `decimal(10,2)` columns coerced to float in PHP and
`round(..., 2)`. Three revenue "bases" are used, each labeled in the UI so
the numbers are never ambiguous:

- **Collected** — `payments.amount` where `payments.paid_on` in range.
  Cash basis, "money in the door." Used for the headline trend, the
  collected total, and the method mix.
- **Invoiced** — `invoice_items.amount` on non-`void` invoices whose
  `invoices.issued_at` is in range. Used for by-provider and by-treatment
  attribution, where a payment (which is invoice-level, not line-level)
  cannot be allocated cleanly. Gross of invoice-level discount — noted in
  the UI.
- **Outstanding (A/R)** — a point-in-time snapshot as of *now*, not
  range-bound: `Invoice` where `status = 'issued'`, loaded with
  `items` + `payments`, filtered to `balance() > 0`, summed. This reuses
  the exact approach already in `DashboardController::index()`'s
  `outstanding` tile, so the reports A/R figure and the dashboard tile
  always agree.

### Revenue section

| Metric | Query |
|---|---|
| **Collected total** | `SUM(payments.amount)` where `paid_on` between `[start,end]` |
| **Invoiced total** | over non-void invoices with `issued_at` in range, loaded `withSum('items as items_total', 'amount')`: `Σ (items_total − discount_amount)` in PHP |
| **Outstanding A/R** | snapshot as above (`{total, count}`) |
| **Collected over time** | `payments` grouped by bucket of `paid_on`, `SUM(amount)`; gap-filled |
| **Invoiced revenue by provider** | `invoice_items` join `invoices` (status ≠ void, `issued_at` in range), `GROUP BY invoice_items.provider_id`, `SUM(invoice_items.amount)`; `provider_id IS NULL` → an "Unattributed" bucket; resolve provider names in one `Provider::whereIn` lookup; sort desc by amount |
| **Revenue by treatment** | `invoice_items` join `invoices` (status ≠ void, `issued_at` in range) LEFT JOIN `treatment_plan_items` on `invoice_items.treatment_plan_item_id`, `GROUP BY treatment_plan_items.treatment`, `SUM(invoice_items.amount)`; `NULL` treatment → **"Ad-hoc / unlinked"** bucket; sort desc; top 8 rows + a summed "Other" row if more |
| **Payment method mix** | `payments` where `paid_on` in range, `GROUP BY method`, `SUM(amount)` + `COUNT(*)`; every `Payment::METHODS` value present (0-filled) |

### Appointments section

Bucketed and grouped on `appointments.start_time`. `requested` appointments
have a `start_time` (set at creation from the preferred date) — but to keep
"appointments in the period" meaning *appointments that were on the
calendar for that period*, the section counts every status **except
`requested`** (a request that was never actioned never became a real
calendar entry). This is stated in a controller docblock.

| Metric | Query |
|---|---|
| **Volume over time** | `appointments` (status ≠ `requested`) grouped by bucket of `start_time`, `COUNT(*)`; gap-filled |
| **Status breakdown** | `GROUP BY status`, `COUNT(*)`, over `start_time` in range, status ≠ `requested`; every relevant status 0-filled: `scheduled`, `checked_in`, `in_treatment`, `completed`, `cancelled`, `no_show`, `declined` |
| **Completion / cancellation / no-show rates** | derived in PHP from the status breakdown: `completed / total`, `(cancelled + declined) / total`, `no_show / total`; `total` = all non-`requested` in range; guard divide-by-zero → `0.0` |
| **By provider** | `GROUP BY provider_id`, `COUNT(*)` total and `SUM(status = 'completed')` completed, `SUM(status = 'no_show')` no-show; provider names resolved in one lookup; sorted desc by total |
| **By type** | `GROUP BY type`, `COUNT(*)`; every `Appointment::TYPES` value 0-filled |

### Patients section

| Metric | Query |
|---|---|
| **New patients over time** | `patients` grouped by bucket of `created_at` in range, `COUNT(*)`; gap-filled; plus the range total |
| **Returning vs first-visit** | of the distinct patients with a `completed` appointment whose `start_time` is in range: a patient is **returning** if they have any `completed` appointment with `start_time` *before* `$start`, else **first-visit**. Two queries (in-range completed patient ids; then how many of those ids have an earlier completed appt). Response: `{returning, first_visit}` counts |
| **No-show patients** | distinct `patient_id` from `appointments` where `status = 'no_show'` and `start_time` in range; response: `count` + a list of up to 20 `{id, name, no_show_count}` sorted desc, each linking to `/patients/{id}` |

## Frontend

### Page shape

`Reports/Index` — `AuthenticatedLayout` with header `<h2>Reports</h2>`.

- **Sticky sub-header**: the range selector — six preset buttons
  (active one filled, same pattern as the `/invoices` filter tabs) plus,
  when `custom` is active or chosen, two `<input type="date">`s and an
  "Apply" button. Changing the range re-issues `router.get(route('reports.index'), {...}, { preserveState: true, preserveScroll: true, replace: true })` — identical to the invoices index filter behaviour. The resolved `label` is shown next to the buttons.
- Below it, three stacked `<section>`s in this order: **Revenue**,
  **Appointments**, **Patients**. Each section = an `<h3>` + a row of
  stat tiles + its chart(s)/table(s). No tabs — the page is meant to be
  scanned top to bottom.
- Standard empty handling: every chart/table shows a muted "No data for
  this period" when its series is all-zero/empty. The page itself never
  404s or blanks.

### Components (new, under `resources/js/Pages/Reports/`)

- `Index.jsx` — page: range selector + section composition.
- `RangePicker.jsx` — the sticky selector.
- `StatTile.jsx` — label + big value (+ optional sub-line like "142
  invoices"). Pure display, no plot. Reused across all three sections.
- `charts.jsx` (or per-file) — a shared Recharts wrapper module the three
  chart components import, so `recharts` has exactly one import site to
  reason about.
- `TrendChart.jsx` — a Recharts `AreaChart` (single series, time on x).
  Used for collected-revenue, appointment-volume, new-patients. Props:
  `data`, `bucket`, `valueFormat` (`'peso'` | `'count'`).
- `BreakdownBars.jsx` — horizontal bar list (Recharts `BarChart`
  `layout="vertical"`, or a plain flex/CSS bar list for ≤ 6 rows — impl
  choice at build). Used for by-provider, by-type, by-treatment,
  method-mix. Props: `rows: [{label, value, sub?}]`, `valueFormat`.
- `RateTiles.jsx` — three labelled percentages (completion / cancellation
  / no-show) with a small proportion bar each.
- `NoShowList.jsx` — the table of no-show patients with `<Link>`s.

Charts follow the `dataviz` skill: **form picked first** (area for
change-over-time, horizontal bars for magnitude-by-category, stat tiles
for single headline numbers — never a pie beyond the 5-slice method mix,
and that one is a bar list, not a donut), a **validated categorical
palette** (run `scripts/validate_palette.js` for the ≥2-series cases —
here only provider/type comparisons, most single-series), **hover
tooltips on by default**, a **legend whenever ≥ 2 series**, recessive
grid/axes, `₱`/count formatting via `formatPeso` and `toLocaleString`.
The implementer loads the `dataviz` skill before writing any chart code.

### Recharts

Add `recharts` to `dependencies` in `package.json` (latest 2.x). It is
imported only by the `Reports/` chart components. Inertia + Vite already
code-split every page into its own chunk, so importing `recharts` from
the Reports components keeps it out of `app.jsx` and every other page's
bundle without extra `React.lazy` plumbing; `Reports/Index` simply pays
the cost on first visit. Justification for the new dependency: a reports
page is precisely where a charting library earns its keep, and
hand-rolling an accessible, hoverable time-series line is disproportionate
effort for a worse result.

## DemoSeeder

`DemoSeeder` currently creates zero invoices/payments and only a thin
spread of appointments, so a freshly-seeded `/reports` is mostly empty.
Extend it (additively — existing recall/calendar fixtures untouched) to
create, across the **last ~120 days**:

- ~25–35 completed/scheduled/cancelled/no-show appointments spread over
  the three providers and four types and across the date range;
- ~15 issued invoices (a mix fully paid, partly paid, unpaid) with 1–3
  line items each, roughly half linked to a `TreatmentPlanItem` (so
  by-treatment has real buckets), and their payments dated within the
  range across 2–3 methods;
- a couple of `draft` and `void` invoices so those are represented.

This is demo data only; it must stay deterministic enough that the page
looks populated but need not be seeded with fixed values.

## Navigation

Add a **"Reports"** link to `AuthenticatedLayout.jsx` — desktop `NavLink`
and mobile `ResponsiveNavLink` — immediately after "Billing" and before
"Inquiries", `active={route().current('reports.*')}`.

## Testing

`tests/Feature/ReportsTest.php` (flat, per `CLAUDE.md`), `RefreshDatabase`,
an `actingUser()` helper — same shape as `WorkspaceTest`.

- `test_guest_cannot_view_reports` — redirect to login.
- `test_it_renders_with_the_default_range` — `assertOk`, component
  `Reports/Index`, `range === 'this_month'`, the three section prop
  groups present.
- `test_custom_range_validation` — `range=custom` with no dates → 422 on
  `start`; `end` before `start` → 422; span > 400 days → 422 on `end`.
- `test_collected_revenue_totals_and_trend` — two payments in range, one
  outside; assert `revenue.collected_total` (int literal — see Inertia
  assertion note below) and that the trend buckets sum to it and are
  gap-filled to the full span.
- `test_invoiced_by_provider_splits_and_buckets_unattributed` — issued
  invoice with items for provider A and a null-provider item; a `void`
  invoice that must be excluded; assert the provider-A row and the
  "Unattributed" row, and that void is absent.
- `test_revenue_by_treatment_buckets_unlinked` — one item linked to a
  TPI, one not; assert the treatment row and the "Ad-hoc / unlinked" row.
- `test_payment_method_mix_zero_fills_all_methods` — one `cash` payment;
  assert all five `Payment::METHODS` present, `cash` non-zero, rest `0`.
- `test_outstanding_ar_matches_the_dashboard_basis` — an issued invoice
  with a partial payment; assert `revenue.outstanding.total` equals the
  invoice's `balance()` and ignores `draft`/`void`.
- `test_appointment_status_breakdown_excludes_requested` — one of each of
  several statuses incl. `requested`; assert `requested` is absent and
  the rest are counted; assert the derived rates.
- `test_appointments_by_provider_and_type` — assert the per-provider
  totals/completed/no-show and the type 0-fill.
- `test_new_patients_trend` — patients created in/out of range.
- `test_returning_vs_first_visit` — patient with a prior completed appt
  (returning) and one without (first-visit).
- `test_no_show_patients_list` — assert count, list ordering, and cap.
- `test_bucket_granularity_switches_with_span` — a `this_month` request
  → `bucket === 'day'`; a `ytd`/`last_12_months` request → `'month'`.

**Inertia assertion note** (from the invoicing sub-project, still in
force): `AssertableInertia` JSON-round-trips props, so whole-number money
props asserted via `->where()` must use **int literals** (`900`, not
`900.0`); direct PHP `assertSame` on a model helper stays float. See
`docs/superpowers/specs/2026-08-29-invoicing-payments-design.md` and the
memory note.

No PHPUnit coverage for the React components (view-only, matches the
`BillingTab`/`PrescriptionsTab` precedent); verified by `npm run build` +
the full suite.

## Out of scope (future slices)

- **Treatments section** — most-common-treatments, treatment completion
  rate, full treatment-revenue attribution. Deferred: the invoice-item →
  treatment-plan-item link is optional, so treatment-level revenue is
  only ever partial; worth its own slice once the clinical side wants it.
- **Recall-adherence report** — blocked on `Patient::dueForRecall()`
  becoming a query (existing `CLAUDE.md` known gap); building a report on
  top of an in-memory scan that already needs rework is the wrong order.
- **CSV / PDF / scheduled-email export** — not requested; the platform's
  "nothing transmitted" posture holds for now. A CSV download (local,
  nothing leaves the server) is the most plausible first addition.
- **Accrual-basis toggle, financial statements, tax/VAT reporting** —
  out of scope, consistent with the invoicing spec's deferrals.
- **Per-provider self-service scoping** ("show me only my numbers") —
  needs RBAC and a Provider↔User link, deferred platform-wide.
- **Comparison to previous period** (▲/▼ vs last month) — a nice second
  iteration; omitted here to keep the first version small.

## Known limitations (to record in `CLAUDE.md` "Known gaps" on ship)

- Every reports query is unbounded by tenant/clinic (there is one clinic)
  and unpaginated; the by-treatment/by-provider lookups load all matching
  `invoice_items` for the range. Fine at demo scale; a real multi-year
  dataset would want summary tables or a date-partitioned index on
  `payments.paid_on`, `invoices.issued_at`, `appointments.start_time`.
- "Invoiced revenue by provider" is gross of invoice-level discount
  (discount is not allocable to a line/provider). The Invoiced total
  *is* net of discount. The UI labels both.
- Outstanding A/R re-derives `balance()` by loading every issued invoice
  with its items and payments on each `/reports` load — same
  already-accepted O(n) pattern as the dashboard tile.
- No timezone handling: "this month" etc. are UTC boundaries. A clinic in
  UTC+8 sees a report day roll over at 08:00 local. Acceptable until the
  app grows real timezone support.
