# DentalCRM — Phase 6 sub-project 4: Prescriptions — Design

Status: approved by user, 2026-08-28.

## Purpose

Phase 6 of `docs/PLATFORM_VISION.md` (§11 Dental Records, §12 Treatment
Plans, §13 Dentist Workspace, plus prescriptions threaded through §6/§9)
bundles five clinical features. Three have shipped: the patient detail
page + dental records, the dental chart / odontogram, and treatment
plans. This is the fourth slice: **prescriptions** — a place to record
the medications a dentist prescribes a patient, attached to the same
`/patients/{patient}` page as a fifth tab.

Today there is nowhere in the app to record that a patient was
prescribed anything. After this slice, staff can:

- See a patient's prescriptions on a new **Prescriptions** tab, grouped
  into Active and Discontinued
- Add a prescription — one medication per entry — optionally attributed
  to a provider and/or linked to one of that patient's own appointments
- Discontinue an active prescription, with an optional reason

A prescription's clinical content (medication, dosage, frequency,
duration, quantity, instructions, and both FK links) is **immutable
once created**. The only post-creation change is a one-way
`active → discontinued` status flip. A wrong prescription is
discontinued and re-entered, not rewritten.

## Constraints

- **No RBAC.** Every authenticated user can create, view, and
  discontinue prescriptions, same as every other staff feature today.
- **One medication per row.** A visit that needs three drugs produces
  three prescription entries. No prescription-header / line-item
  parent entity — this matches the flat list shape of every other
  Phase 6 tab (`DentalRecord`, `ToothCondition`, `TreatmentPlanItem`).
- **No transmission of any kind.** Nothing is emailed, printed to PDF,
  faxed, or sent to a pharmacy. This is consistent with the app-wide
  "no SMS, mail only via the `log` driver" hard constraint. A printable
  prescription slip is a plausible future slice but is explicitly out
  of scope here (see "Out of scope").
- **No edit and no delete of a prescription's clinical content** — no
  route, no controller method, no UI. The only mutation is the
  discontinue action.
- **No dentist-role-scoped view** — deferred until roles exist, same as
  every other Phase 6 slice.

## Architecture

Same Laravel 12 + Inertia 2 + React 18 app, no new packages. One
migration, one model, one controller (`store` + `update`), one route
pair, one new frontend tab component, a small shared formatter module,
and a `show()` addition to the existing `PatientController`.

```
routes/web.php (auth group, alongside the existing patient sub-resource routes)
  POST  /patients/{patient}/prescriptions                 Admin\PrescriptionController@store   name: prescriptions.store
  PATCH /patients/{patient}/prescriptions/{prescription}  Admin\PrescriptionController@update  name: prescriptions.update
```

```
database/migrations/2026_08_28_120000_create_prescriptions_table.php   new
app/Models/Prescription.php                                            new
app/Models/Patient.php                                                 + prescriptions() relation
app/Http/Controllers/Admin/PatientController.php                       + prescriptions prop in show()
app/Http/Controllers/Admin/PrescriptionController.php                  new

resources/js/Pages/Patients/format.js            new — shared formatDate / formatDateTime / formatPeso
resources/js/Pages/Patients/PrescriptionsTab.jsx  new — tab body + New Prescription modal + Discontinue modal
resources/js/Pages/Patients/Show.jsx              + Prescriptions tab button, render <PrescriptionsTab>,
                                                    import formatters from ./format instead of local copies
```

### `Show.jsx` size / componentisation

`Patients/Show.jsx` is already ~865 lines with four tabs, all inline.
Adding a fifth tab plus two modals inline would push it past ~1100
lines. To keep the change contained:

- The **new** Prescriptions tab body and its two modals go in their own
  component, `resources/js/Pages/Patients/PrescriptionsTab.jsx`.
- The three date/currency formatters currently defined at the top of
  `Show.jsx` (`formatDate`, `formatDateTime`, `formatPeso`) move to a
  new `resources/js/Pages/Patients/format.js`. `Show.jsx` imports them
  from there and its local copies are deleted. This is a pure-function
  move with no behaviour change, and it gives `PrescriptionsTab.jsx` a
  place to import the same helpers from.
- The existing four tab bodies (Overview, Dental Records, Dental Chart,
  Treatment Plan) stay inline in `Show.jsx` and are **not** touched
  beyond the formatter import swap. Fully extracting each tab into its
  own component is worthwhile but is deliberately left as a follow-up —
  it would be a large diff across working, only-indirectly-tested code
  and is not required to ship prescriptions.

## Data model

New `prescriptions` table:

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `patient_id` | `foreignId` | required, `constrained()->cascadeOnDelete()` — matches `dental_records.patient_id`; deleting a patient removes their prescriptions |
| `provider_id` | `foreignId` nullable | `constrained()->nullOnDelete()` — the prescriber; deleting a provider preserves the prescription, just clears attribution |
| `appointment_id` | `foreignId` nullable | `constrained()->nullOnDelete()` — deleting an appointment preserves the prescription |
| `medication` | `string` | required (e.g. "Amoxicillin") |
| `dosage` | `string` | required (e.g. "500 mg") |
| `frequency` | `string` | required (e.g. "3 times daily") |
| `duration` | `string` nullable | (e.g. "7 days") |
| `quantity` | `string` nullable | (e.g. "21 capsules") |
| `instructions` | `text` nullable | (e.g. "Take after meals. Complete the full course.") |
| `status` | `string` | `->default('active')`; the only other value is `discontinued` |
| `discontinued_at` | `timestamp` nullable | set server-side when the prescription is discontinued; null while active |
| `discontinued_reason` | `string` nullable | optional free text captured with the discontinue action |
| `created_by` | `foreignId` → `users` | required, `constrained('users')`, set server-side only, never mass-assigned |
| `created_at` / `updated_at` | `timestamps()` | `updated_at` is meaningful here — `status`/`discontinued_at` change — unlike the append-only `DentalRecord`/`ToothCondition` |

`duration` and `quantity` are free-text strings, not structured
numbers/enums — dentists write "7 days", "1 week", "PRN", "21 tabs",
"1 tube" and the app should not fight that. Same reasoning as
`frequency`.

`Prescription::STATUSES` is a `const` array
(`['active', 'discontinued']`), the same pattern as
`Appointment::STATUSES` and `TreatmentPlanItem::STATUSES`, used by the
`update` validation and (indirectly, for the group labels) the
frontend.

`Prescription::$fillable` = `patient_id`, `provider_id`,
`appointment_id`, `medication`, `dosage`, `frequency`, `duration`,
`quantity`, `instructions`. It deliberately excludes `status`,
`discontinued_at`, `discontinued_reason`, and `created_by` — all four
are set server-side only, never from request input.

`protected $casts = ['discontinued_at' => 'datetime'];`

`Prescription` defines `patient()` (`belongsTo(Patient::class)`),
`provider()` (`belongsTo(Provider::class)`), `appointment()`
(`belongsTo(Appointment::class)`), and `creator()`
(`belongsTo(User::class, 'created_by')`).

`Patient::prescriptions(): HasMany` orders newest-first at the
relationship definition (`->latest('created_at')`), matching
`Patient::dentalRecords()`. Active and discontinued prescriptions come
back in one list; the Active/Discontinued split is done client-side by
`status`, matching how the Treatment Plan tab splits Active/Resolved.

## `PatientController::show()`

Adds one prop alongside the existing `dentalRecords`, `toothConditions`,
`treatmentPlanItems`, `providers`, `appointments`:

```php
'prescriptions' => $patient->prescriptions()
    ->with(['provider', 'appointment', 'creator'])
    ->get()
    ->map(fn (Prescription $rx) => [
        'id' => $rx->id,
        'medication' => $rx->medication,
        'dosage' => $rx->dosage,
        'frequency' => $rx->frequency,
        'duration' => $rx->duration,
        'quantity' => $rx->quantity,
        'instructions' => $rx->instructions,
        'status' => $rx->status,
        'discontinued_at' => $rx->discontinued_at?->toIso8601String(),
        'discontinued_reason' => $rx->discontinued_reason,
        'provider_name' => $rx->provider?->name,
        'appointment_start_time' => $rx->appointment?->start_time?->toIso8601String(),
        'created_at' => $rx->created_at->toIso8601String(),
        'creator_name' => $rx->creator->name,
    ]),
```

The existing `providers` and `appointments` props already carry
everything the New Prescription form's `<select>`s need; no change to
those queries.

## `PrescriptionController`

Class docblock states the mutation rule explicitly (mirroring
`TreatmentPlanItemController`'s docblock): clinical content is fixed at
creation; the only post-creation change is a one-way
`active → discontinued` flip via `update()`; there is deliberately no
`destroy()`, no matching route, and no UI to reach one.

### `store(Request $request, Patient $patient): RedirectResponse`

`POST /patients/{patient}/prescriptions`:

1. Validates:
   - `medication`: `required`, `string`, `max:255`
   - `dosage`: `required`, `string`, `max:255`
   - `frequency`: `required`, `string`, `max:255`
   - `duration`: `nullable`, `string`, `max:255`
   - `quantity`: `nullable`, `string`, `max:255`
   - `instructions`: `nullable`, `string`
   - `provider_id`: `nullable`, `exists:providers,id`
   - `appointment_id`: `nullable`,
     `Rule::exists('appointments', 'id')->where('patient_id', $patient->id)`
     — rejects an appointment ID that exists but belongs to a different
     patient, not just one that doesn't exist
2. Creates via `$patient->prescriptions()->make([...$validated])`, then
   sets `$rx->created_by = $request->user()->id;` by direct property
   assignment (it isn't fillable) and `$rx->save()`. `status` is left
   to its `active` column default — never accepted from the request.
3. `return back();` (matches every other controller in the app).

### `update(Request $request, Patient $patient, Prescription $prescription): RedirectResponse`

`PATCH /patients/{patient}/prescriptions/{prescription}` — the
**discontinue** action, and nothing else:

1. `abort_unless($prescription->patient_id === $patient->id, 404)` —
   same pattern as `TreatmentPlanItemController::update()`.
2. `abort_unless($prescription->status === 'active', 403)` — a
   prescription can only be discontinued once; re-submitting against an
   already-discontinued row is a client error, not a silent no-op.
3. Validates: `discontinued_reason`: `nullable`, `string`, `max:255`.
4. Sets `status = 'discontinued'`, `discontinued_at = now()`,
   `discontinued_reason` = the validated value (or null). Any other
   keys in the request body — `medication`, `dosage`, `status`, etc. —
   are never read, so they cannot be used to edit clinical content
   through this endpoint.
5. `return back();`

## `Patients/Show` page — Prescriptions tab

A fifth tab button, "Prescriptions", after "Treatment Plan", using the
exact same tab-button markup as the others. Its body renders
`<PrescriptionsTab patient={patient} prescriptions={prescriptions}
providers={providers} appointments={appointments} />`.

### `PrescriptionsTab.jsx`

```
[+ New Prescription]  button

Active
  ↓ card per active prescription, newest first:
    - "<medication> <dosage>" (bold) · <frequency> · <duration> · <quantity>
      (each segment shown only if present)
    - provider name (or "—") · linked appointment date (if any)
    - instructions (if any)
    - "Prescribed by <creator_name> on <created_at date>"
    - [Discontinue] button  → opens the Discontinue modal for this row
  ↓ empty state: "No active prescriptions."

Discontinued
  ↓ card per discontinued prescription, newest first, visually muted /
    medication line struck through (same treatment as a cancelled
    treatment-plan item):
    - same medication/frequency/etc. line
    - "Discontinued on <discontinued_at date>" + reason (if any)
    - "Prescribed by <creator_name> on <created_at date>"
  ↓ empty state: "No discontinued prescriptions."
```

**New Prescription modal** — fields: medication, dosage, frequency
(all required), duration, quantity (optional), provider (optional
`<select>`, "No provider" default), link-to-appointment (optional
`<select>`, this patient's appointments only, "No linked appointment"
default), instructions (textarea). Submits
`prescriptionForm.post(route('prescriptions.store', patient.id))` with
`preserveScroll: true`, resets and closes `onSuccess`. Per-field error
text under each input, matching the New Record / New Treatment Item
modals.

**Discontinue modal** — shows the medication being discontinued in its
heading, one optional "Reason (optional)" text input, Cancel / confirm
buttons. Submits
`discontinueForm.patch(route('prescriptions.update', { patient: patient.id, prescription: rx.id }))`
with `preserveScroll: true`, resets and closes `onSuccess`. The confirm
button is styled as a normal primary action (not a red destructive
one — discontinuing is reversible in spirit: the row and its history
remain).

No edit affordance anywhere on a prescription. No delete affordance
anywhere. The append-only-content rule is enforced by there being no
endpoint, not by hiding a button.

### `format.js`

```js
export function formatDate(iso) { /* moved verbatim from Show.jsx */ }
export function formatDateTime(iso) { /* moved verbatim from Show.jsx */ }
export function formatPeso(amount) { /* moved verbatim from Show.jsx */ }
```

`Show.jsx` imports `{ formatDate, formatDateTime, formatPeso }` from
`./format` and deletes its three local definitions. No other change to
`Show.jsx`'s existing tabs.

## Testing

New `tests/Feature/PrescriptionTest.php` (flat, per the repo
convention):

- **Auth** — an unauthenticated `POST /patients/{patient}/prescriptions`
  and `PATCH /patients/{patient}/prescriptions/{prescription}` each
  redirect to login.
- **Creation** — a valid submission creates a `Prescription` with the
  submitted fields; `status` is `active`; `created_by` is the
  authenticated user's ID even when the request body includes a
  different `created_by` value (proves it's ignored, not just unset).
- **Required fields** — a submission missing `medication`, `dosage`, or
  `frequency` (each tested) is rejected with a validation error; no row
  is created.
- **Optional fields / links** — a prescription can be created with
  `provider_id`, `appointment_id`, `duration`, `quantity`, and
  `instructions` all null.
- **Appointment ownership** — an `appointment_id` belonging to a
  *different* patient is rejected; no row is created.
- **Discontinue** — a `PATCH` against an active prescription sets
  `status` to `discontinued`, sets `discontinued_at`, and stores the
  submitted `discontinued_reason`; a `medication` / `dosage` / `status`
  value in the same request body has no effect (the drug fields are
  unchanged, status is `discontinued` not whatever was sent).
- **Discontinue is one-way** — a `PATCH` against an
  already-discontinued prescription returns 403 and leaves the row
  unchanged.
- **Cross-patient PATCH** — `PATCH /patients/{A}/prescriptions/{rx}`
  where `rx` belongs to patient B returns 404.
- **Show page** — `patients.show` returns the patient's prescriptions
  newest-first, and does not include another patient's prescriptions.
- **No delete route** — asserts no route named `prescriptions.destroy`
  exists (guards the no-delete constraint against silent regression).

A `PrescriptionFactory` is added (states or defaults for a valid active
prescription; a `discontinued()` state) for use by the tests, matching
the existing model factories.

## Documentation

`CLAUDE.md` "Planning workflow" → "Shipped so far" gains a **Phase 6,
sub-project 4** bullet describing prescriptions: the fifth
`/patients/{patient}` tab, the flat one-medication-per-row
`Prescription` model, content immutable at creation with a one-way
`active → discontinued` PATCH, no delete ever, and the deferred
printable-slip follow-up.

## Out of scope / explicitly not addressed here

- **Printable / PDF / any transmittable prescription output.** This is
  the most likely next slice ("Phase 6 sub-project 4b") but is not in
  this one — shipping the record first matches how dental records
  shipped without a print view.
- Drug-name autocomplete or any medication database / formulary.
- Drug-interaction or allergy checking (the patient allergy field
  itself doesn't exist yet — that's Phase 7 intake / medical history).
- Refill counts / refill tracking / prescription expiry.
- e-Prescribing, pharmacy routing, or any outbound send (hard
  constraint).
- Patient-portal visibility of prescriptions (Phase 4 proper — no
  patient accounts exist).
- Editing or deleting a prescription's clinical content (by design, not
  a gap — a correction is a new prescription plus discontinuing the
  wrong one).
- Prescription filtering / search / pagination on the tab.
- Any change to the existing four `Patients/Show` tabs beyond swapping
  the formatter import, and any change to existing patient / appointment
  behaviour.
