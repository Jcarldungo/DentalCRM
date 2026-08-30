# DentalCRM — Phase 8 sub-project 2: Data integrity & abuse resistance — Design

Status: draft, 2026-08-30.

## Purpose

The second of three Phase 8 security sub-projects. Where 8.1 fixes
*who can authenticate*, this one fixes what the application does with
requests that are already legitimate — destructive actions with no
guard, check-then-act races around money and clinical state, a crafted
`PATCH` that permanently breaks a shared page, and public endpoints
that can be abused without ever logging in.

The unifying theme is that the app makes strong promises it does not
enforce. Five models are documented as "append-only, no delete, ever"
and there is a single route that erases all of them at once. An invoice
state machine forbids `void → issued` and a concurrent pair of requests
performs exactly that transition. A payment cannot be recorded against
a voided invoice, except when it can.

Sibling sub-projects: **8.1 Access control & authentication**
(specced), **8.3 Transport & deployment hardening** (specced).

## Findings addressed

| # | Finding | Severity |
|---|---------|----------|
| B1 | `DELETE /patients/{patient}` is unguarded and cascade-destroys every append-only clinical record *and* the patient's whole billing ledger including recorded payments | High |
| B2 | `PaymentController` re-fetches the invoice under a row lock but never re-checks its **status**, so a payment can be written against a concurrently-voided invoice | Medium |
| B3 | `InvoiceController::transition()` decides transition legality from a pre-lock read, permitting a `void → issued` resurrection under concurrency | Medium |
| B4 | A `requested` appointment can be forced to `checked_in` with a NULL provider/`end_time`, permanently 500-ing `/queue` for every staff member | Medium |
| B5 | `ProfileController::destroy` guards 3 of 8 `created_by` foreign keys, and logs the user out *before* the delete that then throws | Low |
| B6 | `paid_on` and `occurred_on` are unbounded in both directions, silently moving revenue outside every `/reports` range | Low |
| B7 | An anonymous booking appends attacker-authored free text to a known patient's record, rendered back to that patient on their own signed lookup page | Medium |
| B8 | Booking-slot exhaustion — `throttle:6,1` against `max_requests_per_slot: 6` lets one IP saturate a slot per minute, with no cap on how far ahead | Medium |
| B9 | `POST /my-appointments` remains a timing oracle for "is this person a patient here", and the rate limiter added to fix mail-bombing made the delta permanent | Medium |
| B10 | `patients.email` has no unique index; concurrent bookings create duplicate patients and the lookup then silently shows only one | Low |
| B11 | Stock movements can be recorded against archived inventory items, landing stock where nothing looks | Informational |
| B12 | `InvoiceController::update()` dispatches on `$request->has('status')`, which is true for a present-but-null key | Informational |

### Verified evidence

- `app/Http/Controllers/Admin/PatientController.php:132-137` — `destroy()`
  is a bare `$patient->delete()`. Six migrations declare
  `foreignId('patient_id')->constrained()->cascadeOnDelete()`
  (`appointments`, `dental_records`, `tooth_conditions`,
  `treatment_plan_items`, `prescriptions`, `invoices`), and `invoices`
  cascades onward to `invoice_items` and `payments`.
- `resources/js/Pages/Patients/Index.jsx:56` — a live delete button is
  wired to it, and `tests/Feature/PatientTest.php:100` currently
  *asserts the deletion succeeds*, locking the behaviour in.
- `ProviderController.php:47-53` already implements exactly the guard
  that is missing — refuse when appointments exist, "Mark them inactive
  instead."
- `PaymentController.php:24` checks status on the route-bound instance;
  `:36-57` locks and re-derives only `balance()`.
- `InvoiceController.php:101` reads `$from = $invoice->status` before
  the lock at `:121`.
- `AppointmentController.php:124-126` — `assertSchedulable()` runs only
  `if ($status === 'scheduled')`; migration `2026_08_25_142016` made
  `provider_id`/`start_time`/`end_time` nullable for guest requests.
  `QueueController.php:34-38` then dereferences `$appointment->provider->name`
  unconditionally.

## Constraints

- **Append-only stays append-only.** Nothing here adds an edit or
  delete route to `DentalRecord`, `ToothCondition`, `Prescription`
  clinical content, or `Payment`/`StockMovement`. The fix for B1 is to
  *stop* a deletion path, not to add a safer one.
- **No external services.** A CAPTCHA on the booking form would be the
  textbook answer to B8, and it is deliberately not used — it means an
  outbound call to a third party, which contradicts the app's "nothing
  is transmitted anywhere" posture and its fictional-clinic stance.
  Rate limiting and input bounds carry the load instead.
- **The public booking flow must stay usable by a real guest.** No
  account, no login, no email round-trip before the request is
  submitted. Hardening must not turn a booking into a two-step flow.
- **Existing lock patterns are correct and stay.** The review confirmed
  `PaymentController` and `StockMovementController` take
  `lockForUpdate()` inside a transaction on the right row, with the
  check after the lock. B2/B3 are about *what* is re-read under that
  lock, not about the locking itself.

## Design

### 1. Patients cannot be deleted once they have any record (B1)

`PatientController::destroy()` mirrors the `ProviderController` guard
already in the codebase:

```php
public function destroy(Patient $patient): RedirectResponse
{
    if ($patient->hasClinicalOrBillingHistory()) {
        return back()->withErrors(['patient' => 'This patient has appointments, clinical records, or billing history and cannot be deleted. Deactivate the record instead.']);
    }

    $patient->delete();

    return back();
}
```

`Patient::hasClinicalOrBillingHistory()` returns true if any of
`appointments`, `dentalRecords`, `toothConditions`, `treatmentPlanItems`,
`prescriptions`, or `invoices` exists. The remaining legitimate use for
`destroy` — removing a patient created by mistake, moments ago, with
nothing attached — keeps working, which is why the route is guarded
rather than removed.

`Patients/Index.jsx` surfaces the returned error the same way
`Providers/Index.jsx` already does.

`tests/Feature/PatientTest.php:100` is rewritten: a patient with no
history is still deletable; a patient with *each* kind of history is
not, and the child rows survive.

**Why not soft-delete or an `active` flag.** It is the larger change (a
migration, an index filter, a UI state, and a decision about whether an
archived patient still appears in search, the queue, and reports) and
it is not what this finding requires. The guard closes the hole today;
patient archival is recorded as a future slice.

### 2. Invoice status is re-checked under the lock (B2)

`PaymentController::store()` gains, immediately after `$locked` is
fetched inside the transaction:

```php
abort_unless($locked->status === 'issued', 403);
```

The pre-lock `abort_unless` at `:24` stays as a cheap early exit. This
makes the invariant symmetric: `InvoiceController` already refuses to
void an invoice that has payments; now `PaymentController` refuses to
pay an invoice that has been voided.

### 3. Transition legality is decided under the lock (B3)

`InvoiceController::transition()` moves three things inside the
transaction, reading from `$locked` rather than the route-bound
instance: the `$from` status, the "a draft with no line items cannot be
issued" check, and the existing payment-count check. The write then
applies a decision made against state that cannot have changed since it
was read.

Concretely, this makes the concurrent `{status: void}` +
`{status: issued}` pair resolve to one winner and one 403, instead of
an issued invoice carrying a non-null `voided_at`.

### 4. Board statuses require a schedulable appointment (B4)

`AppointmentController::update()` currently runs `assertSchedulable()`
only for `scheduled`. It runs for every status that puts an appointment
on the queue board:

```php
// On Appointment — the fourth consumer of this set, so it stops being
// duplicated in WorkspaceController, QueueController, and Show.jsx.
public const BOARD_STATUSES = ['scheduled', 'checked_in', 'in_treatment', 'completed'];
```

`assertSchedulable()` is called for any target status in
`BOARD_STATUSES`, so an appointment cannot reach the board without a
provider, a start, an end, and a type. `QueueController`'s projection
additionally null-guards `provider` and `end_time` so a row that
predates this fix cannot 500 the page.

CLAUDE.md's "Known gaps" notes that the appointment status set is
duplicated in three places and that "a shared const on `Appointment`
is the natural home once a fourth consumer appears." This is that
fourth consumer, so `WorkspaceController`, `QueueController`, and
`Patients/Show.jsx` are migrated onto the const and the gap is closed.

### 5. Profile deletion checks every owning table (B5)

`ProfileController::destroy()` guards all eight `created_by` owners —
the current three plus `prescriptions`, `invoices`, `payments`,
`inventory_items`, `stock_movements` — and reorders the operation so
the destructive step happens before the session is torn down:

1. guard; return `back()->withErrors(...)` if the user authored anything
2. `DB::transaction(fn () => $user->delete())`
3. `Auth::logout()`, `session()->invalidate()`, `regenerateToken()`

Today the logout runs first and the delete then throws a `QueryException`,
leaving the user logged out, the account intact, and the session
neither invalidated nor rotated.

### 6. Money and stock dates are bounded (B6)

`paid_on` (`PaymentController.php:29`) and `occurred_on`
(`StockMovementController.php:31`) both gain `before_or_equal:today`.
A back-dated payment currently reduces an invoice balance immediately
while placing the revenue outside every `/reports` range, so collected
revenue and the payment-method mix under-report while A/R correctly
drops — a silent reporting inconsistency with no way to notice it.

No lower bound is imposed; a floor would need a defensible business
rule (the invoice's issue date? the clinic's opening date?) and the
review found no abuse that a floor prevents. Recorded as a known gap.

### 7. Guest bookings cannot inject free text into a patient's record (B7)

`BookingController` validates `service_interest` and
`dentist_preference` with `Rule::in(...)` against canonical lists,
instead of accepting 255 characters of anything.

The lists move to `config/clinic.php`:

```php
'bookable_services' => ['General Check-up & Cleaning', ...],
'bookable_dentists' => ['No preference', ...],
```

and `PublicSiteController::book()` passes them to `Book.jsx` as Inertia
props, so the `<select>` the guest sees and the rule the server enforces
have one source. `resources/js/Data/services.js` keeps its richer
marketing content (description, icon, price) for the services and
dentists marketing pages — the two are related but not the same data,
and the sync requirement is recorded as a known gap alongside the
project's other documented const-duplication cases.

This removes the vector where `service_interest=URGENT — your account is
overdue, call 0917-555-0100` is stored on a victim's record and rendered
verbatim to them on their own signed lookup page
(`AppointmentLookupResults.jsx:81`) inside the clinic's branded UI.

The review confirmed `notes` is **not** exposed on the lookup page, so
it stays free text. It also confirmed `findOrCreatePatient` does *not*
overwrite an existing patient's name/phone/DOB — the hijack-by-overwrite
attack does not exist. What remains is appending, and constraining the
displayed fields is the proportionate fix; not merging guest bookings
into existing patient records at all is recorded as a future slice.

### 8. Booking abuse is bounded (B8)

Two changes:

- `POST /book` moves from `throttle:6,1` (6/min — enough for one IP to
  saturate a whole slot every minute) to `throttle:3,60`. Three booking
  requests per hour per IP is generous for a genuine guest and makes
  slot exhaustion cost real infrastructure.
- `preferred_date` gains `before_or_equal:` today + 180 days. Today
  only `after_or_equal:today` is enforced, so `9999-12-31` validates and
  slots can be poisoned arbitrarily far ahead.

`POST /contact` keeps `throttle:6,1`; it creates an inquiry, not a
scheduling resource, so exhaustion does not apply.

### 9. The appointment lookup does equal work on both branches (B9)

`AppointmentLookupController::send()` is restructured so the
hit and miss paths are indistinguishable:

- the per-email rate limiter runs on the normalized submitted email
  **regardless of whether a patient matched** — today `&&`
  short-circuits the whole limiter on a miss, and (worse) the limiter
  added in `e5a0567` leaves a permanent, non-decaying delta once an
  address is over its limit
- the match-and-mail decision moves inside a queued job, so the request
  path performs the same work either way and the branch is resolved on
  the worker

The endpoint's documented no-enumeration guarantee
(`app/Mail/AppointmentLookupLink.php:14-19`) then holds against timing,
not only against response shape — the review confirmed the response
shape itself is already uniform.

`POST /book`'s inverted oracle (a hit skips an `INSERT`) is **not**
fixed here. With §8's 3/hour/IP limit, the ~50-100 paired samples an
attack needs cost days from one address, and every sample leaves a
visible junk patient and appointment row for staff to notice. Recorded
as a known gap rather than restructured, because making it symmetric
means deferring patient creation to a worker and changing what the
guest is told on submit.

### 10. Patient email is unique (B10)

A migration adds a unique index to `patients.email`. The column is
nullable and MySQL/MariaDB permits multiple NULLs, so staff-created
walk-in patients without an email are unaffected. The default
`utf8mb4_unicode_ci` collation makes the index case-insensitive, which
matches the `LOWER(email)` lookups in `BookingController` and
`AppointmentLookupController`.

`findOrCreatePatient` becomes a `firstOrCreate` inside a transaction, so
the check-then-act that currently lets two concurrent bookings create
two patient rows — after which `->first()` silently returns only the
lower id and the lookup omits the other's appointments — is closed at
both the application and the schema level.

`DemoSeeder` is checked for colliding fixture emails before the
migration ships.

### 11. Small correctness fixes (B11, B12)

- `StockMovementController::store()` rejects a movement against an
  archived (`active = false`) item. Stock currently moves into a place
  the default `/inventory` view and the dashboard tile both filter out.
- `InvoiceController::update()` dispatches on `$request->filled('status')`
  rather than `has('status')`. `has()` is true for a present-but-null
  key, so a future edit payload carrying `status: null` would route into
  `transition()` and die on "The status field is required" instead of
  saving. Today's UI keeps the two payloads separate
  (`Invoices/Show.jsx:22`), so this is hardening an implicit frontend
  contract that the whole draft-freeze guarantee rests on.

## Testing

Each finding gets a test written first that fails against current
`master`. Flat `tests/Feature/`, per CLAUDE.md.

- `PatientTest.php` — a patient with each kind of history cannot be
  deleted and its children survive; a history-free patient still can.
- `PaymentTest.php` — a payment against a voided invoice is refused;
  the pre-lock and post-lock checks are both exercised.
- `InvoiceTest.php` — `void → issued` is refused; a draft with no items
  still cannot be issued when the check runs under the lock.
- `QueueTest.php` — `PATCH`ing a `requested` appointment to `checked_in`
  without a provider/`end_time` is rejected; `/queue` renders 200 with a
  pre-existing malformed row.
- `ProfileTest.php` — a user who authored a payment, an invoice, a
  prescription, an inventory item, or a stock movement cannot delete
  their profile, gets an error rather than a 500, and **stays logged in**.
- `PaymentTest.php` / `StockMovementTest.php` — a future `paid_on` /
  `occurred_on` is rejected.
- `BookingTest.php` — an out-of-list `service_interest` or
  `dentist_preference` is rejected; a `preferred_date` beyond 180 days
  is rejected; the throttle trips on the fourth request in an hour.
- `AppointmentLookupTest.php` — the rate limiter is consumed for a
  non-existent email exactly as for an existing one (asserted on limiter
  state, which is deterministic, rather than on wall-clock timing).
- A migration test that two patients cannot share an email, and that
  multiple null emails are fine.
- `StockMovementTest.php` — a movement against an archived item is
  rejected.

## Out of scope (future slices)

- **Patient archival / soft delete** — an `active` flag on `Patient`
  with the index, search, queue, and reports semantics that implies.
  The guard in §1 makes it unnecessary for safety; it is a UX feature.
- **Not merging guest bookings into existing patient records** —
  creating an unlinked request for staff to reconcile is the stronger
  design and a behavioural change to a shipped Phase 3 feature.
- **CAPTCHA or proof-of-work on public forms** — needs an external
  service; see Constraints.
- **Making `POST /book` timing-symmetric** — see §9.
- **A general audit log** of who changed what, which would make several
  of these findings detectable rather than merely preventable.
- **Constraining `Appointment` status transitions into a real state
  machine.** CLAUDE.md already records that any status can become any
  other; §4 fixes the one transition that produces a persistent crash,
  not the general problem.

## Known gaps (to record in `CLAUDE.md` on ship)

- `paid_on` / `occurred_on` have an upper bound but no lower bound, so a
  payment can still be dated arbitrarily far in the past.
- `config/clinic.php`'s `bookable_services` / `bookable_dentists` and
  `resources/js/Data/services.js` / `dentists.js` describe overlapping
  data with only a comment asserting they stay in sync — the same
  situation as the app's other documented const-duplication cases.
- `POST /book` remains a weak, rate-limited, noisy patient-existence
  timing oracle.
- Guest bookings still merge into an existing patient record on an email
  match, so anyone who knows a patient's email can append a `requested`
  appointment to their history for staff to triage.
- `Patient::hasClinicalOrBillingHistory()` runs six `exists()` queries
  on every delete attempt. Negligible — it runs once, on a rare action.
