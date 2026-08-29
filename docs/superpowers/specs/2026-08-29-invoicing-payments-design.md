# DentalCRM — Phase 7 sub-project 1: Invoicing & Payments — Design

Status: approved by user, 2026-08-29.

## Purpose

Phase 7 of `docs/PLATFORM_VISION.md` (§16: Billing) bundles invoices,
discounts, deposits, partial payments, outstanding balances, payment
history, payment methods, and receipts — far too much for one spec.
This is sub-project 1: the core billing loop. A staff member can raise
an invoice for a patient, issue it, record payments against it, and see
amount due / paid / balance wherever it matters.

Staff can:
- Open a patient's "Billing" tab and see every invoice for that
  patient, each with its total and outstanding balance, plus a
  patient-level billed / paid / outstanding summary
- Create a new invoice: one or more free-text line items (each with an
  optional link to one of the patient's treatment-plan items, which
  pre-fills the description and amount), a flat peso discount, and
  notes — the invoice starts as a `draft`
- Edit a `draft` invoice's line items, discount, and notes
- Issue a `draft` invoice, which freezes its line items and discount
- Record one or more payments against an `issued` invoice (amount,
  method, date paid, optional reference and note)
- Void a `draft` invoice at any time, or an `issued` invoice only while
  it has no payments
- Open a dedicated invoice page showing line items, payments, and the
  running balance
- Open a clinic-wide `/invoices` index of all invoices, filtered by
  status
- See a dashboard "Outstanding" tile — total unpaid balance across all
  issued invoices, and a count, linking to the filtered index

Partial payments need no special handling: an invoice simply has
several `payments` rows and its balance is derived. "Paid" is a
**derived display state** (an issued invoice whose balance has reached
zero), not a stored status.

## Constraints

- No RBAC. Every authenticated user can create, edit, issue, void, and
  take payment on any invoice — same as every other staff feature in
  the app today.
- **Nothing is transmitted anywhere.** No emailed or printable receipt
  / invoice slip in this slice — the same stance the prescriptions
  slice took (`docs/superpowers/specs/2026-08-28-prescriptions-design.md`).
  A printable slip is a plausible future slice.
- **Payments are strictly append-only** — no update route, no destroy
  route, no controller method for either, no UI control to reach one. A
  mistaken payment is corrected by a future refund/reversal concept,
  which this slice does not build. This is called out again in
  "Out of scope".
- An invoice is **partially mutable**: line items, discount, and notes
  are editable only while `status === 'draft'`. Issuing freezes them.
  `patient_id` is fixed at creation. There is no invoice `destroy` —
  retiring an invoice means voiding it.
- **Overpayment is rejected**: a payment's amount must be `> 0` and
  `<= the invoice's current balance`. Balance can never go negative.
- The `discount_amount` is a flat peso figure, validated to not exceed
  the line-item subtotal. No percentage discounts, no per-line
  discounts, no tax.
- Money columns are `decimal(10,2)`, pesos (`₱`) — matching
  `treatment_plan_items.estimated_cost`. All derived figures
  (`subtotal`, `total`, `amount_paid`, `balance`) are computed
  server-side with database aggregates and sent as props; none are
  stored.
- The invoice **number is derived, display-only**: `INV-` followed by
  the zero-padded primary key (`INV-000042`). No separate number column
  or counter — the same "derive the display shape" approach the rest of
  Phase 6 uses. A real gapless invoice sequence is a future concern,
  noted in "Known gaps".
- Invoice status transitions are governed by an **explicit state
  machine** (see "Lifecycle"), not a bare `Rule::in()` — unlike
  `Appointment` / `TreatmentPlanItem`, this is money and the allowed
  moves are narrow and tested.
- The optional per-line link to a `TreatmentPlanItem` is
  **informational only**. Issuing an invoice does not change the linked
  items; the two subsystems stay independent. The line stores its own
  frozen `provider_id` copy so an issued invoice stays auditable
  without chasing the mutable `TreatmentPlanItem`.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump()`, no unused
  imports, no commented-out code.
- Commits carry NO `Co-Authored-By` trailer (matches repo history).
  Short imperative subjects.

## Architecture

Same Laravel 12 + Inertia 2 + React 18 + Tailwind 3 app, no new
packages. Three migrations, three models, two controllers, five routes,
two new Inertia pages, one new patient-tab component, and additions to
`PatientController::show()`, `Patients/Show.jsx`, `DashboardController`,
`Dashboard.jsx`, and `AuthenticatedLayout.jsx`.

```
routes/web.php (auth group, after the /workspace route)
  GET    /invoices                          Admin\InvoiceController@index   name: invoices.index
  POST   /invoices                          Admin\InvoiceController@store   name: invoices.store
  GET    /invoices/{invoice}                Admin\InvoiceController@show    name: invoices.show
  PATCH  /invoices/{invoice}                Admin\InvoiceController@update  name: invoices.update
  POST   /invoices/{invoice}/payments       Admin\PaymentController@store   name: invoice-payments.store
```

```
database/migrations/..._create_invoices_table.php          new
database/migrations/..._create_invoice_items_table.php     new
database/migrations/..._create_payments_table.php          new
database/factories/InvoiceFactory.php                      new
database/factories/InvoiceItemFactory.php                  new
database/factories/PaymentFactory.php                      new
app/Models/Invoice.php                                     new
app/Models/InvoiceItem.php                                 new
app/Models/Payment.php                                     new
app/Models/Patient.php                                     + invoices() relation
app/Http/Controllers/Admin/InvoiceController.php           new
app/Http/Controllers/Admin/PaymentController.php           new
app/Http/Controllers/Admin/PatientController.php           + invoices prop on show()
app/Http/Controllers/Admin/DashboardController.php         + outstanding tile data

resources/js/Pages/Invoices/Index.jsx                      new
resources/js/Pages/Invoices/Show.jsx                       new
resources/js/Pages/Patients/BillingTab.jsx                 new
resources/js/Pages/Patients/Show.jsx                       + Billing tab
resources/js/Pages/Dashboard.jsx                           + Outstanding tile
resources/js/Layouts/AuthenticatedLayout.jsx              + Billing nav link (desktop + responsive)

CLAUDE.md                                                  + shipped-so-far bullet + Known gaps notes
```

## Data model

### `invoices` table

| Column | Type | Notes |
|---|---|---|
| `patient_id` | `foreignId` | required, `cascadeOnDelete()` — matches `treatment_plan_items.patient_id` |
| `status` | `string` | one of `Invoice::STATUSES`, defaults to `draft` |
| `discount_amount` | `decimal(10,2)` | default `0`, `₱`, validated `>= 0` and `<= line-item subtotal` |
| `notes` | `text` nullable | |
| `issued_at` | `timestamp` nullable | set server-side when `status` becomes `issued` |
| `voided_at` | `timestamp` nullable | set server-side when `status` becomes `void` |
| `created_by` | `foreignId` → `users` | required, set server-side only, never mass-assigned |
| `created_at`/`updated_at` | `timestamp` | standard timestamps |

### `invoice_items` table

| Column | Type | Notes |
|---|---|---|
| `invoice_id` | `foreignId` | required, `cascadeOnDelete()` |
| `treatment_plan_item_id` | `foreignId` nullable | `nullOnDelete()`; optional informational link |
| `provider_id` | `foreignId` nullable | `nullOnDelete()`; frozen copy from the linked TPI (or set manually), for auditable issued lines |
| `description` | `string` | required, max 255, free text |
| `amount` | `decimal(10,2)` | required, numeric, `>= 0`, `₱` |
| `created_at`/`updated_at` | `timestamp` | standard timestamps |

### `payments` table

| Column | Type | Notes |
|---|---|---|
| `invoice_id` | `foreignId` | required, `cascadeOnDelete()` |
| `amount` | `decimal(10,2)` | required, numeric, `> 0`, `<= invoice balance at time of payment`, `₱` |
| `method` | `string` | one of `Payment::METHODS` |
| `paid_on` | `date` | required, defaults to today when omitted |
| `reference` | `string` nullable | max 255 — cheque number, transfer ref, etc. |
| `note` | `string` nullable | max 255 |
| `created_by` | `foreignId` → `users` | required, set server-side only, never mass-assigned |
| `created_at`/`updated_at` | `timestamp` | standard timestamps |

### Consts

```php
Invoice::STATUSES = ['draft', 'issued', 'void'];
Payment::METHODS  = ['cash', 'card', 'bank_transfer', 'check', 'other'];
```

Both follow the existing `DentalRecord::TYPES` / `TreatmentPlanItem::PRIORITIES`
pattern — used by both server validation and the frontend `<select>`s.

### Casts & relations

- `Invoice`: `discount_amount` → `decimal:2`, `issued_at`/`voided_at` →
  `datetime`. `items(): HasMany` (ordered by `id`), `payments():
  HasMany` (ordered by `paid_on` then `id`), `patient(): BelongsTo`,
  `creator(): BelongsTo(User::class, 'created_by')`.
- `InvoiceItem`: `amount` → `decimal:2`. `invoice(): BelongsTo`,
  `treatmentPlanItem(): BelongsTo`, `provider(): BelongsTo`.
- `Payment`: `amount` → `decimal:2`, `paid_on` → `date:Y-m-d`.
  `invoice(): BelongsTo`, `creator(): BelongsTo(User::class,
  'created_by')`.
- `Patient::invoices(): HasMany` — `->latest('created_at')->latest('id')`
  (newest first, like `prescriptions()`).

`created_by` is excluded from every model's `$fillable`; each
controller sets it from `$request->user()->id` after validation, so a
client-supplied `created_by` is never trusted — identical to
`TreatmentPlanItem` / `Prescription`.

### Derived figures (never stored)

For a given invoice:

```
subtotal    = sum(invoice_items.amount)
total       = subtotal - discount_amount          (never < 0: discount is capped at subtotal)
amount_paid = sum(payments.amount)
balance     = total - amount_paid                 (never < 0: payments are capped at balance)
```

`is_paid` (display only) = `status === 'issued' && balance <= 0`.

The controllers compute these with DB aggregates (`withSum` / manual
`selectRaw`), round to 2 decimals, and pass them as plain numbers in
the props. No trigger, no cached column, no observer.

## Lifecycle

```
                 issue
   draft ───────────────────▶ issued ──(balance reaches 0 via payments)──▶ displays "Paid"
     │                          │                                          (status stays 'issued')
     │ void                     │ void  (only when the invoice has zero payments)
     ▼                          ▼
   void  ◀───────────────────  void   (terminal)
```

- **draft** — line items, `discount_amount`, and `notes` are editable
  via `PATCH /invoices/{invoice}`. No payment may be recorded
  (`POST .../payments` → 403). May be voided unconditionally.
- **issue** — `PATCH` with `status: issued`. Requires the invoice to
  have at least one line item (else a validation error). Sets
  `issued_at = now()`. After this, any `PATCH` touching `items` /
  `discount_amount` / `notes` → 403, and the edit UI is gone.
- **issued** — `POST .../payments` is allowed. May be voided **only if
  `payments()->count() === 0`**; otherwise the void button is absent
  and the endpoint returns 403.
- **void** — terminal. No further transitions, no payments, no edits.
  Excluded from every outstanding / revenue figure.
- **paid** — not a status. Any consumer needing it derives
  `status === 'issued' && balance <= 0`.

Any transition not on this diagram (e.g. `issued → draft`,
`void → issued`, `draft → draft`) is rejected by
`InvoiceController::update()` with a validation error, and each
rejection has a test.

## `InvoiceController`

### `index(Request $request): Response`

`GET /invoices`. Renders `Invoices/Index` with:

- `invoices` — every invoice, `with('patient:id,first_name,last_name')`,
  `withSum('items as subtotal', 'amount')` and
  `withSum('payments as amount_paid', 'amount')`, newest first. Mapped
  to `[{ id, number ('INV-000042'), patient_id, patient_name, status,
  total, amount_paid, balance, is_paid, created_at (ISO) }]`.
- `filters` — `['status' => <one of 'all'|'draft'|'outstanding'|'paid'|'void', default 'all'>]`,
  echoed back from the validated query string.
- The list is filtered **server-side** by the `status` param:
  - `all` — everything
  - `draft` — `status === 'draft'`
  - `outstanding` — `status === 'issued'` and `balance > 0`
  - `paid` — `status === 'issued'` and `balance <= 0`
  - `void` — `status === 'void'`
- No pagination — consistent with `patients.index` / `providers.index`.
  Flagged in "Known gaps".

Validation: `status` → `nullable`, `Rule::in(['all', 'draft', 'outstanding', 'paid', 'void'])`.

### `show(Invoice $invoice): Response`

`GET /invoices/{invoice}`. Renders `Invoices/Show` with a single
`invoice` prop:

```php
[
  'id' => $invoice->id,
  'number' => 'INV-'.str_pad($invoice->id, 6, '0', STR_PAD_LEFT),
  'status' => $invoice->status,
  'patient' => ['id' => ..., 'full_name' => ...],
  'notes' => $invoice->notes,
  'discount_amount' => (float) $invoice->discount_amount,
  'subtotal' => ..., 'total' => ..., 'amount_paid' => ..., 'balance' => ...,
  'is_paid' => $invoice->status === 'issued' && $balance <= 0,
  'issued_at' => $invoice->issued_at?->toIso8601String(),
  'voided_at' => $invoice->voided_at?->toIso8601String(),
  'created_at' => $invoice->created_at->toIso8601String(),
  'creator_name' => $invoice->creator->name,
  'items' => [ { id, description, amount, treatment_plan_item_id,
                 treatment_plan_item_label (string|null — "<treatment> · tooth <n>" or null),
                 provider_name } ],
  'payments' => [ { id, amount, method, paid_on (Y-m-d), reference, note,
                    created_at (ISO), creator_name } ],
]
```

Also passes `treatmentPlanItems` — the patient's items eligible to link
(`planned` / `scheduled` / `in_progress` / `completed`), shape
`[{ id, label ('<treatment> · tooth <n>' / '<treatment>'), estimated_cost, provider_id }]` —
so the "edit draft" UI can offer the same pre-fill dropdown the
create-invoice modal uses.

### `store(Request $request): RedirectResponse`

`POST /invoices`:

1. Validates:
   - `patient_id` — `required`, `exists:patients,id`
   - `discount_amount` — `nullable`, `numeric`, `min:0`
   - `notes` — `nullable`, `string`
   - `items` — `required`, `array`, `min:1`
   - `items.*.description` — `required`, `string`, `max:255`
   - `items.*.amount` — `required`, `numeric`, `min:0`
   - `items.*.treatment_plan_item_id` — `nullable`,
     `Rule::exists('treatment_plan_items', 'id')->where('patient_id', <patient_id>)`
     (rejects a TPI belonging to another patient)
2. After validation, checks `discount_amount <= sum(items.*.amount)`;
   on failure throws a `ValidationException` on `discount_amount`.
3. Creates the invoice: `status = 'draft'`, `discount_amount` (default
   `0`), `notes`, `created_by = $request->user()->id` (direct
   assignment, not mass-assignment).
4. Creates each `invoice_items` row. If a line has a
   `treatment_plan_item_id`, its `provider_id` is copied from that TPI
   (unless the request explicitly supplies one — it does not in this
   slice, so: always copied from the TPI when linked, else null).
5. `return redirect()->route('invoices.show', $invoice)` — a new
   invoice opens on its own page (unlike the append-in-place tabs, an
   invoice is a document you then work on).

### `update(Request $request, Invoice $invoice): RedirectResponse`

`PATCH /invoices/{invoice}`. Two mutually-exclusive modes, decided by
whether the request contains a `status` key:

**Transition mode** (`status` present):

- Validates `status` → `required`, `Rule::in(Invoice::STATUSES)`.
- Allowed moves, else `ValidationException` on `status`:
  - `draft → issued` — requires `$invoice->items()->count() >= 1`;
    sets `issued_at = now()`.
  - `draft → void` — sets `voided_at = now()`.
  - `issued → void` — requires `$invoice->payments()->count() === 0`;
    sets `voided_at = now()`.
- `status` / `issued_at` / `voided_at` are set by direct assignment
  (not `$fillable`), so nothing else in the body can ride along.

**Edit mode** (`status` absent):

- `abort_unless($invoice->status === 'draft', 403)`.
- Validates the same `items` / `discount_amount` / `notes` rules as
  `store()` (minus `patient_id`), including the
  `discount_amount <= subtotal` check.
- Replaces the line items wholesale: delete all existing
  `invoice_items` for the invoice, recreate from the request array
  (same TPI-ownership validation and `provider_id` copy as `store()`).
  Full-replace keeps the endpoint and the Inertia form simple; a draft
  invoice has no payments or history to preserve.
- Updates `discount_amount` and `notes`.

Both modes `return back()`.

### No `destroy`

There is no `destroy` method, no `invoices.destroy` route, and no UI
affordance. A test asserts the route name does not exist.

## `PaymentController`

### `store(Request $request, Invoice $invoice): RedirectResponse`

`POST /invoices/{invoice}/payments`:

1. `abort_unless($invoice->status === 'issued', 403)` — no payments on
   a draft or a void invoice.
2. Validates:
   - `amount` — `required`, `numeric`, `gt:0`
   - `method` — `required`, `Rule::in(Payment::METHODS)`
   - `paid_on` — `nullable`, `date`
   - `reference` — `nullable`, `string`, `max:255`
   - `note` — `nullable`, `string`, `max:255`
3. Computes the invoice's current `balance`
   (`total - amount_paid`). If `amount > balance`, throws a
   `ValidationException` on `amount` ("Payment exceeds the ₱X.XX
   balance.").
4. Creates the `payments` row: validated fields, `paid_on` defaulting
   to `today()` when omitted, `created_by = $request->user()->id`
   (direct assignment).
5. `return back()`.

No `update`, no `destroy`, no other methods.

## `PatientController::show()` additions

Adds an `invoices` prop, same map-to-array style as the other tabs:

```php
'invoices' => $patient->invoices()
    ->withSum('items as subtotal', 'amount')
    ->withSum('payments as amount_paid', 'amount')
    ->get()
    ->map(fn (Invoice $invoice) => [
        'id' => $invoice->id,
        'number' => 'INV-'.str_pad($invoice->id, 6, '0', STR_PAD_LEFT),
        'status' => $invoice->status,
        'total' => round((float) $invoice->subtotal - (float) $invoice->discount_amount, 2),
        'amount_paid' => round((float) $invoice->amount_paid, 2),
        'balance' => round((float) $invoice->subtotal - (float) $invoice->discount_amount - (float) $invoice->amount_paid, 2),
        'is_paid' => $invoice->status === 'issued'
            && round((float) $invoice->subtotal - (float) $invoice->discount_amount - (float) $invoice->amount_paid, 2) <= 0,
        'created_at' => $invoice->created_at->toIso8601String(),
    ]),
```

(`subtotal` / `amount_paid` come back `null` when there are no rows —
coerce with `(float)`.)

The existing `providers` / `appointments` props are unchanged. The
create-invoice modal needs the patient's linkable treatment-plan items;
`treatmentPlanItems` is **already passed** to `Patients/Show` (Phase 6
sub-project 3) — the Billing tab filters it client-side to the four
open/completed statuses for its dropdown, no new prop.

## `DashboardController` additions

Adds an `outstanding` prop:

```php
'outstanding' => [
    'total' => <sum of balances over all status='issued' invoices with balance > 0>,
    'count' => <how many such invoices>,
],
```

Computed with one aggregate query joining the sums (issued invoices,
`subtotal - discount_amount - amount_paid > 0`). `Dashboard.jsx` renders
a single tile: `₱X,XXX.XX outstanding across N invoices`, linking to
`route('invoices.index', { status: 'outstanding' })`. When `count` is
0 the tile shows "No outstanding balances."

## Frontend

### `resources/js/Pages/Patients/BillingTab.jsx`

Own component (like `PrescriptionsTab.jsx`). Props: `patient`,
`invoices`, `treatmentPlanItems`. Renders:

- A summary row: **Billed** `formatPeso(sum totals)` · **Paid**
  `formatPeso(sum amount_paid)` · **Outstanding**
  `formatPeso(sum balances)` (void invoices excluded from all three).
- `[+ New Invoice]` → modal:
  - Repeatable line rows. Each row: a "link to treatment-plan item"
    `<select>` (options = `treatmentPlanItems` filtered to
    `planned`/`scheduled`/`in_progress`/`completed`, labelled
    `"<treatment> · tooth <n>"`), a description text input, an amount
    number input. Choosing a TPI pre-fills description (`treatment`) and
    amount (`estimated_cost`); both stay editable. `[+ Add line]` /
    remove-line buttons. At least one line required.
  - A flat discount input (₱), a notes textarea.
  - Submits `POST invoices.store`. On success Inertia follows the
    redirect to the invoice page.
- A table of this patient's invoices: `number` · created date · total ·
  balance · status badge (`draft` grey, `issued` blue, **Paid** green
  when `is_paid`, `void` struck-through grey). The row links to
  `route('invoices.show', id)`.
- Empty state when the patient has no invoices.

### `resources/js/Pages/Invoices/Show.jsx`

`AuthenticatedLayout`. Prop: `invoice`, `treatmentPlanItems`. Sections:

- **Header** — `number`, status badge, `[View patient]` link to
  `route('patients.show', invoice.patient.id)`, created/issued/voided
  timestamps, `creator_name`.
- **Line items** — table (description, linked-TPI label if any,
  provider, amount). Footer: subtotal, − discount, **= total**.
  - When `status === 'draft'`: an `[Edit]` control opens the same
    line-editor modal as the create flow, pre-filled, submitting
    `PATCH invoices.update` in edit mode (no `status` key).
- **Payments** — table (paid_on, method, reference, amount, recorded-by).
  Footer: amount paid, **balance due**.
  - When `status === 'issued'` and `balance > 0`: `[Record payment]`
    modal — amount (defaults to `balance`), method `<select>`, paid-on
    date (defaults to today), reference, note — submits
    `POST invoice-payments.store`.
- **Actions** by state:
  - `draft` — `[Issue invoice]` (`PATCH` `status: issued`),
    `[Void]` (`PATCH` `status: void`).
  - `issued` & no payments — `[Void]`.
  - `issued` with payments, or `void` — no lifecycle actions.
- A `void` invoice renders read-only with a "Voided" banner.
- `is_paid` shows a green "Paid in full" banner.

**Dialog caution:** all confirmations are in-page modal components
(same pattern as the other tabs' modals) — no `window.confirm`, per the
repo's browser-dialog constraint.

### `resources/js/Pages/Invoices/Index.jsx`

`AuthenticatedLayout`. Props: `invoices`, `filters`. Renders:

- Filter tabs: All / Draft / Outstanding / Paid / Void — each an
  Inertia `<Link>` to `route('invoices.index', { status })` with
  `preserveState` / `preserveScroll` / `replace`.
- A table: `number` · patient name (links to the patient) · created
  date · total · paid · balance · status badge. Each row links to
  `route('invoices.show', id)`.
- Empty state per filter ("No outstanding invoices.", etc.).

### `resources/js/Pages/Dashboard.jsx`

Adds one tile beside the existing recall list: outstanding total +
count, or "No outstanding balances", linking to the filtered index.

### `resources/js/Layouts/AuthenticatedLayout.jsx`

A `<NavLink>` (desktop) and `<ResponsiveNavLink>` (mobile), text
"Billing", `href={route('invoices.index')}`,
`active={route().current('invoices.*')}`, placed after the "Workspace"
links.

### Formatters

`Invoices/Show.jsx`, `Invoices/Index.jsx`, and `BillingTab.jsx` import
`formatPeso` / `formatDate` from `resources/js/Pages/Patients/format.js`
(already the shared home for these three formatters since the
prescriptions slice). No new formatter file.

## Testing

### `tests/Feature/InvoiceTest.php`

- **Auth** — a guest hitting `GET /invoices`, `GET /invoices/{invoice}`,
  `POST /invoices`, `PATCH /invoices/{invoice}` is redirected to login.
- **Create** — a valid `POST /invoices` with two line items creates a
  `draft` invoice + two `invoice_items`; `created_by` is the
  authenticated user even when the body carries a different
  `created_by`; `status` in the body is ignored (always `draft`).
- **Create with a TPI link** — a line referencing one of the patient's
  treatment-plan items stores `treatment_plan_item_id` and copies that
  item's `provider_id` onto the line.
- **Create validation** — no `items` / empty `items` rejected; a line
  with no `description` or no `amount` rejected; a negative
  `items.*.amount` rejected; a `treatment_plan_item_id` belonging to a
  different patient rejected; `discount_amount` greater than the
  line-item subtotal rejected; no invoice is created in any of these.
- **Edit a draft** — `PATCH` (no `status`) replaces the line items
  wholesale, updates `discount_amount` and `notes`; the old items are
  gone.
- **Edit is draft-only** — `PATCH` edit-mode against an `issued` or
  `void` invoice → 403; items unchanged.
- **Issue** — `PATCH status: issued` on a draft with ≥1 item flips the
  status and stamps `issued_at`; a draft with zero items cannot be
  issued (validation error).
- **Void** — a `draft` voids unconditionally and stamps `voided_at`; an
  `issued` invoice with no payments voids; an `issued` invoice **with a
  payment** cannot be voided (403 / validation error) and stays
  `issued`.
- **Illegal transitions** — `issued → draft`, `void → issued`,
  `void → draft` all rejected.
- **Derived figures** — `show` returns `subtotal`, `total`
  (`subtotal - discount`), `amount_paid`, `balance`; after a partial
  payment the balance reflects it; `is_paid` is `false` until the
  balance hits 0, then `true` while `status` stays `issued`.
- **Index filters** — `?status=outstanding` returns only issued
  invoices with a positive balance; `?status=paid` only issued invoices
  at zero balance; `?status=void` only void; `?status=draft` only
  draft; default returns all; an invalid `status` value is rejected.
- **Index scoping of a void invoice** — a void invoice never appears
  under `outstanding` and its balance is not in the dashboard
  `outstanding.total`.
- **Patient Billing prop** — `PatientController::show()` `invoices`
  prop lists only that patient's invoices with correct `total` /
  `amount_paid` / `balance` / `is_paid`.
- **No destroy route** — asserts no route named `invoices.destroy`.

### `tests/Feature/PaymentTest.php`

- **Auth** — a guest `POST /invoices/{invoice}/payments` redirects to
  login.
- **Happy path** — a payment on an `issued` invoice creates a
  `payments` row with `created_by` = the auth user; `paid_on` defaults
  to today when omitted and is respected when supplied.
- **Only on issued** — a payment against a `draft` or `void` invoice →
  403; no payment row created.
- **Amount bounds** — `amount` of `0` or negative rejected; `amount`
  greater than the current balance rejected with the balance in the
  message; a payment exactly equal to the balance is accepted and
  drives `is_paid` true.
- **Partial payments accumulate** — two payments summing to less than
  the total leave a positive balance; a third closing the gap makes
  `is_paid` true.
- **Method validation** — a `method` outside `Payment::METHODS`
  rejected.
- **Append-only** — asserts no route named `invoice-payments.update` or
  `invoice-payments.destroy`, and `PaymentController` has no such
  methods.

### Factories

- `InvoiceFactory` — `patient_id` from `Patient::factory()`,
  `status` `draft`, `discount_amount` `0`, `created_by` from
  `User::factory()`. States: `issued()` (sets `status`, `issued_at`),
  `void()` (sets `status`, `voided_at`).
- `InvoiceItemFactory` — `invoice_id` from `Invoice::factory()`,
  `description` a `word`-based phrase, `amount` a realistic peso figure,
  `treatment_plan_item_id` / `provider_id` null.
- `PaymentFactory` — `invoice_id` from `Invoice::factory()->issued()`,
  `amount`, `method` `cash`, `paid_on` `today()`, `created_by` from
  `User::factory()`.

### Full-suite gate

`"$HOME/.config/herd/bin/php.bat" artisan test` — all pre-existing
tests plus the new files pass. `npm run build` succeeds (Vite manifest
for the new pages; a fresh worktree needs the build before the ~32
page-render tests pass).

## Out of scope / explicitly not addressed here

Deferred to later Phase 7 sub-projects or future phases per
`docs/PLATFORM_VISION.md`:

- **Receipts / printable or emailed invoice slips** — nothing is
  transmitted or rendered to PDF in this slice.
- **Refunds / payment reversal / editing or deleting a payment** —
  payments are append-only with no correction path yet.
- **Deposits / pre-payment credit** — no paying against a draft, no
  patient credit balance.
- **Overpayment / change due** — payments are capped at the balance.
- **Percentage discounts, per-line discounts, tax / VAT lines.**
- **A real gapless invoice-number sequence** — the number is derived
  from the primary key.
- **Revenue reporting (by day / dentist / treatment)** — Phase 7
  sub-project 4 (Reports & Analytics, vision §20); the frozen
  `invoice_items.provider_id` is laid down now to make it possible
  later without a migration.
- **Statements, ageing buckets, dunning, payment reminders.**
- **Insurance / third-party payers / claims.**
- **Linking an invoice to an appointment**, or issuing an invoice
  changing the linked `TreatmentPlanItem` status — the subsystems stay
  independent; the per-line link is informational.
- **Pagination / search on `/invoices`** — consistent with the other
  unpaginated index pages; noted in Known gaps.
- **Any change to existing patient / appointment / dental-record /
  tooth-condition / treatment-plan / prescription behavior.**

## Known gaps (to add to `CLAUDE.md`)

- Invoice numbers are derived from the primary key (`INV-` + padded
  id), so they are not gapless and shift if rows are ever hard-deleted.
  A dedicated sequence/counter is the fix if a real clinic needs
  statutory invoice numbering.
- `/invoices` loads every invoice with no pagination or search — same
  as `patients.index` / `providers.index`. Fine at demo scale; add
  pagination + a filter query if the table grows.
- `balance` / `total` are recomputed from aggregates on every read
  (index, patient tab, dashboard tile, invoice page). No cached column.
  Fine at demo scale.
- The invoice status set (`draft` / `issued` / `void`) and the
  "linkable treatment-plan item" status set now live in both
  `InvoiceController` and the frontend — same docblock-sync situation
  the `WorkspaceController` / `QueueController` / `Patients/Show.jsx`
  note already describes.
