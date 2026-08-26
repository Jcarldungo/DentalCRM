# DentalCRM — Phase 6 sub-project 3: Treatment Plans — Design

Status: approved by user, 2026-08-26.

## Purpose

Phase 6 of `docs/PLATFORM_VISION.md` (§12: Treatment Plans) bundles
five things that don't belong in one spec (see
`docs/superpowers/specs/2026-08-26-dental-records-design.md`, which
shipped sub-project 1: the patient detail page and free-text dental
records; and `docs/superpowers/specs/2026-08-26-dental-chart-design.md`,
sub-project 2: the per-tooth condition chart). This is sub-project 3: a
flat, working list of proposed treatments for a patient, each trackable
through a status lifecycle. Prescriptions and the dentist workspace
remain out of scope, deferred to their own future sub-projects.

Staff can:
- Open a patient's "Treatment Plan" tab and see every proposed
  treatment for that patient, grouped into Active
  (`planned`/`scheduled`/`in_progress`) and Resolved
  (`completed`/`cancelled`)
- Add a new treatment item: what it is, which tooth (optional), which
  provider is proposing/performing it (optional), which of the
  patient's appointments it's tied to (optional), its estimated cost,
  and its priority
- Update an existing item's status, priority, estimated cost, and
  notes as the plan progresses

Unlike `DentalRecord` and `ToothCondition`, a `TreatmentPlanItem` is
**mutable** — it's a working plan, not a historical log. There is no
per-status-change history; the row holds current state and standard
`updated_at` tracks when it last changed. What created the item
(`treatment`, `tooth_number`, `provider_id`, `appointment_id`,
`patient_id`) is fixed at creation and never edited — a wrong entry is
cancelled and re-entered, not rewritten.

## Constraints

- No RBAC. Every authenticated user can create and update treatment
  plan items, same as every other staff feature in the app today.
- No grouping/parent "Treatment Plan" entity — a patient's plan is
  simply all their `TreatmentPlanItem` rows. A patient can't have
  multiple named alternative plans in this slice.
- No prescriptions or dentist-role-scoped workspace — deferred to
  their own future sub-projects.
- No delete of a `TreatmentPlanItem`, ever — no route, no controller
  method, no UI. Retiring an item means setting its status to
  `cancelled`.
- `update()` may only change `status`, `priority`, `estimated_cost`,
  and `notes`. `treatment`, `tooth_number`, `provider_id`, and
  `appointment_id` are immutable after creation — any of those keys
  present in an update request are silently ignored, not validated or
  applied.
- No billing/invoice linkage — `estimated_cost` is a plain decimal
  field with no connection to any billing entity, since billing
  (Phase 7) doesn't exist yet.
- No new `providers`/`appointments` data loading — the tab reuses the
  same `providers` and `appointments` props `PatientController::show()`
  already passes for the Dental Records and Dental Chart tabs.

## Architecture

Same Laravel app, no new packages. One migration, one model, one
controller (`store` and `update`), two routes, and additions to the
existing `PatientController::show()` and `Patients/Show.jsx`.

```
routes/web.php (auth group, alongside the existing dental-records/tooth-conditions routes)
  POST  /patients/{patient}/treatment-plan-items                          Admin\TreatmentPlanItemController@store    name: treatment-plan-items.store
  PATCH /patients/{patient}/treatment-plan-items/{treatmentPlanItem}      Admin\TreatmentPlanItemController@update   name: treatment-plan-items.update
```

```
database/migrations/..._create_treatment_plan_items_table.php   new
app/Models/TreatmentPlanItem.php                                 new
app/Models/Patient.php                                           + treatmentPlanItems() relation
app/Http/Controllers/Admin/PatientController.php                  + treatmentPlanItems prop on show()
app/Http/Controllers/Admin/TreatmentPlanItemController.php        new

resources/js/Pages/Patients/Show.jsx                              + Treatment Plan tab
```

## Data model

New `treatment_plan_items` table:

| Column | Type | Notes |
|---|---|---|
| `patient_id` | `foreignId` | required, `cascadeOnDelete()` — matches `dental_records.patient_id` |
| `provider_id` | `foreignId` nullable | `nullOnDelete()` |
| `appointment_id` | `foreignId` nullable | `nullOnDelete()` |
| `tooth_number` | `unsignedTinyInteger` nullable | 1-32, Universal numbering; null = whole-mouth/multi-tooth treatment |
| `treatment` | `string` | required, free text (e.g. "Root Canal Treatment") |
| `estimated_cost` | `decimal(10,2)` | required, ₱ |
| `priority` | `string` | one of `TreatmentPlanItem::PRIORITIES` |
| `status` | `string` | one of `TreatmentPlanItem::STATUSES`, defaults to `planned` |
| `notes` | `text` nullable | |
| `created_by` | `foreignId` → `users` | required, set server-side only, never mass-assigned |
| `created_at`/`updated_at` | `timestamp` | standard timestamps — unlike `DentalRecord`/`ToothCondition`, this row is mutable |

`TreatmentPlanItem::PRIORITIES` is a `const` array:

```php
['low', 'medium', 'high']
```

`TreatmentPlanItem::STATUSES` is a `const` array, the vision doc's §12
list minus "Scheduled" duplicating "planned"'s intent — kept exactly as
named there:

```php
['planned', 'scheduled', 'in_progress', 'completed', 'cancelled']
```

— both consts follow the same pattern as `DentalRecord::TYPES` and
`Appointment::STATUSES`, used by both validation and the frontend
`<select>`s. As with `Appointment` (see `CLAUDE.md`'s Known gaps),
status transitions are unconstrained beyond `Rule::in()` — any status
can become any other; no state-machine enforcement in this slice.

`TreatmentPlanItem` defines `patient()`, `provider()`
(`belongsTo(Provider::class)`), `appointment()`
(`belongsTo(Appointment::class)`), and `creator()`
(`belongsTo(User::class, 'created_by')`) — same relation set as
`DentalRecord` and `ToothCondition`.

`Patient::treatmentPlanItems(): HasMany` orders oldest-first at the
relationship definition (`->oldest('created_at')`) — creation order,
not a newest-first log, since this is a working list rather than a
history. The Active/Resolved grouping for display is computed
client-side in React, the same "derive display shape in the frontend"
approach already used for tooth conditions' current-condition-per-tooth.

`created_by` is excluded from `TreatmentPlanItem::$fillable`; the
controller sets it explicitly from `$request->user()->id` after
validation, so a client-supplied `created_by` is never trusted.

## `TreatmentPlanItemController::store()`

`POST /patients/{patient}/treatment-plan-items`:

1. Validates:
   - `treatment`: required string, max 255
   - `tooth_number`: nullable, integer, between 1 and 32
   - `provider_id`: nullable, `exists:providers,id`
   - `appointment_id`: nullable, `Rule::exists('appointments', 'id')->where('patient_id', $patient->id)` —
     rejects an appointment ID that exists but belongs to a different
     patient
   - `estimated_cost`: required, numeric, min 0
   - `priority`: required, `Rule::in(TreatmentPlanItem::PRIORITIES)`
   - `notes`: nullable string
2. `status` is never accepted from the request — every item is created
   via `$patient->treatmentPlanItems()->make($validated + ['status' => 'planned'])`,
   sets `created_by = $request->user()->id` directly, saves.
3. Redirects back (`return back()`), matching
   `DentalRecordController::store()`/`ToothConditionController::store()`.

## `TreatmentPlanItemController::update()`

`PATCH /patients/{patient}/treatment-plan-items/{treatmentPlanItem}`:

1. Validates:
   - `status`: required, `Rule::in(TreatmentPlanItem::STATUSES)`
   - `priority`: required, `Rule::in(TreatmentPlanItem::PRIORITIES)`
   - `estimated_cost`: required, numeric, min 0
   - `notes`: nullable string
2. Route-model-binds both `Patient` and `TreatmentPlanItem`, then
   explicitly checks `$treatmentPlanItem->patient_id === $patient->id`
   and aborts with a 404 if not — the same explicit-check style as the
   `appointment_id` ownership validation in `store()`, rather than
   relying on Laravel's implicit scoped-binding conventions.
3. Updates only the four validated fields via `$treatmentPlanItem->update($validated)`
   — `treatment`, `tooth_number`, `provider_id`, `appointment_id`,
   `patient_id`, and `created_by` are never touched by this method,
   regardless of what the request body contains.
4. Redirects back (`return back()`).

## `PatientController::show()` additions

Adds a `treatmentPlanItems` prop, same map-to-array shape as the
existing `dentalRecords`/`toothConditions` props:

```php
'treatmentPlanItems' => $patient->treatmentPlanItems()
    ->with(['provider', 'appointment', 'creator'])
    ->get()
    ->map(fn (TreatmentPlanItem $item) => [
        'id' => $item->id,
        'tooth_number' => $item->tooth_number,
        'treatment' => $item->treatment,
        'estimated_cost' => $item->estimated_cost,
        'priority' => $item->priority,
        'status' => $item->status,
        'notes' => $item->notes,
        'provider_name' => $item->provider?->name,
        'appointment_start_time' => $item->appointment?->start_time?->toIso8601String(),
        'created_at' => $item->created_at->toIso8601String(),
        'creator_name' => $item->creator->name,
    ]),
```

`providers` and `appointments` props are unchanged and reused as-is —
no new data loading.

## Patients/Show page — Treatment Plan tab

Fourth tab alongside Overview, Dental Records, and Dental Chart:

```
Patient header (name)
├── [Overview] [Dental Records] [Dental Chart] [Treatment Plan]   ← tabs
│
└── Treatment Plan tab
    [+ New Treatment Item] button
    ↓ modal: treatment, tooth (optional 1-32 select), provider (optional
      select, reuses the existing providers prop), link to appointment
      (optional select, reuses the existing appointments prop, this
      patient's appointments only), estimated cost (₱), priority
      (low/medium/high select), notes
      — no status field; every new item starts at "planned"

    Active section (planned / scheduled / in_progress, in creation order)
    Resolved section (completed / cancelled, in creation order)
      each row: treatment · tooth (or "—") · provider name (or "—") ·
        ₱estimated_cost · priority badge · status badge · notes
      [Edit] → modal: status (select), priority (select), estimated
        cost, notes — pre-filled with current values
```

Submits to `treatment-plan-items.store`/`.update`. On success, the
standard Inertia form POST/PATCH already refreshes page props, so the
list and its Active/Resolved grouping update without any extra reload
logic.

No delete affordance anywhere on an item — same as dental records and
tooth conditions, enforced by there being no endpoint to call.

## Testing

New `tests/Feature/TreatmentPlanItemTest.php`:

- **Auth** — an unauthenticated `POST /patients/{patient}/treatment-plan-items`
  and `PATCH .../treatment-plan-items/{item}` both redirect to login.
- **Creation** — a valid submission creates a `TreatmentPlanItem` with
  status `planned` regardless of any `status` value present in the
  request body; `created_by` is the authenticated user's ID even when
  the request body includes a different `created_by` value.
- **Validation** — `tooth_number` outside 1-32 is rejected; `priority`
  outside `TreatmentPlanItem::PRIORITIES` is rejected; a missing
  `treatment` or `estimated_cost` is rejected; a negative
  `estimated_cost` is rejected.
- **Provider/appointment optional** — an item can be created with both
  null; an item can be created with a valid `provider_id` and/or
  `appointment_id`.
- **Appointment ownership** — an `appointment_id` belonging to a
  *different* patient is rejected with a validation error; no item is
  created.
- **Update** — a valid `PATCH` changes `status`, `priority`,
  `estimated_cost`, and `notes`; the same request's `treatment`,
  `tooth_number`, `provider_id`, and `appointment_id` values (even if
  present and different from current) are silently ignored — the
  original values persist unchanged.
- **Update scoping** — a `PATCH` to `/patients/{other_patient}/treatment-plan-items/{item}`,
  where `{item}` belongs to a different patient, 404s.
- **Show page** — `treatmentPlanItems` for one patient does not
  include another patient's items, and reflects updates made via
  `PATCH`.
- **No delete route** — asserts no route exists named
  `treatment-plan-items.destroy`.

## Out of scope / explicitly not addressed here

Named/grouped treatment plans (multiple alternative plans per patient),
prescriptions, document/X-ray attachments, any dentist-role-scoped
workspace, billing/invoice linkage, and per-status-change history — all
remain deferred to their own future sub-projects/phases per
`docs/PLATFORM_VISION.md`. Also deliberately not included: deleting a
`TreatmentPlanItem`, editing `treatment`/`tooth_number`/`provider_id`/
`appointment_id` after creation, a state-machine constraining valid
status transitions, filtering or pagination of the treatment plan list,
and any change to existing patient/appointment/dental-record/
tooth-condition behavior.
