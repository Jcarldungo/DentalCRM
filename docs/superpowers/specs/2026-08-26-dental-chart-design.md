# DentalCRM — Phase 6 sub-project 2: Dental Chart / Odontogram — Design

Status: approved by user, 2026-08-26.

## Purpose

Phase 6 of `docs/PLATFORM_VISION.md` (§10: Dental Chart / Odontogram)
bundles five things that don't belong in one spec (see
`docs/superpowers/specs/2026-08-26-dental-records-design.md`, which
shipped sub-project 1: the patient detail page and free-text dental
records). This is sub-project 2: a per-tooth condition chart on that
same patient detail page. Treatment plans, prescriptions, and the
dentist workspace remain out of scope, deferred to their own future
sub-projects.

Staff can:
- Open a patient's "Dental Chart" tab and see all 32 teeth laid out in
  a clinical horseshoe arrangement, each color-coded by its current
  condition
- Click a tooth to see its full condition history, newest first
- Record a new condition for a tooth, optionally attributed to a
  provider and/or linked to one of that patient's own appointments

A tooth condition entry, once created, cannot be edited or deleted —
same append-only rule as `DentalRecord`. A correction is a new entry,
not a rewrite of history.

## Constraints

- No RBAC. Every authenticated user can create and view tooth
  conditions, same as every other staff feature in the app today.
- No per-surface charting (mesial/distal/occlusal/buccal/lingual) —
  conditions are recorded per whole tooth. A real 5-surface odontogram
  is a much larger UI/data problem than this slice needs.
- No treatment plans, prescriptions, or dentist-role-scoped workspace —
  deferred to their own future sub-projects.
- No edit or delete of a `ToothCondition` once created — no route, no
  controller method, no UI for either.
- Universal numbering (1-32), not FDI or Palmer notation.
- No new `providers`/`appointments` data loading — the chart reuses the
  same props `PatientController::show()` already passes for the Dental
  Records tab.

## Architecture

Same Laravel app, no new packages. One migration, one model, one
controller (`store` only), one route, and additions to the existing
`PatientController::show()` and `Patients/Show.jsx`.

```
routes/web.php (auth group, alongside the existing dental-records route)
  POST /patients/{patient}/tooth-conditions   Admin\ToothConditionController@store   name: tooth-conditions.store
```

```
database/migrations/..._create_tooth_conditions_table.php   new
app/Models/ToothCondition.php                                new
app/Models/Patient.php                                       + toothConditions() relation
app/Http/Controllers/Admin/PatientController.php              + toothConditions prop on show()
app/Http/Controllers/Admin/ToothConditionController.php       new

resources/js/Pages/Patients/Show.jsx                          + Dental Chart tab
```

## Data model

New `tooth_conditions` table:

| Column | Type | Notes |
|---|---|---|
| `patient_id` | `foreignId` | required, `cascadeOnDelete()` — matches `dental_records.patient_id` |
| `tooth_number` | `unsignedTinyInteger` | required, 1-32, Universal numbering |
| `condition` | `string` | one of `ToothCondition::CONDITIONS` |
| `notes` | `text` nullable | |
| `provider_id` | `foreignId` nullable | `nullOnDelete()` |
| `appointment_id` | `foreignId` nullable | `nullOnDelete()` |
| `created_by` | `foreignId` → `users` | required, set server-side only, never mass-assigned |
| `created_at` | `timestamp` | `useCurrent()` |

No `updated_at` column — append-only by construction, same as
`DentalRecord`. `ToothCondition` sets `const UPDATED_AT = null;`.

`ToothCondition::CONDITIONS` is a `const` array:

```php
['healthy', 'caries', 'filling', 'crown', 'missing', 'extraction', 'root_canal', 'implant', 'other']
```

— the vision doc's §10 list, plus `other` as a catch-all, the same
pattern as `DentalRecord::TYPES`. `healthy` is an explicit, selectable
condition (staff can log "examined, tooth 14 is healthy" as a record),
distinct from a tooth having no history at all yet.

`ToothCondition` defines `patient()`, `provider()` (`belongsTo(Provider::class)`),
`appointment()` (`belongsTo(Appointment::class)`), and `creator()`
(`belongsTo(User::class, 'created_by')`) — same relation set as
`DentalRecord`.

`Patient::toothConditions(): HasMany` orders newest-first at the
relationship definition (`->latest('created_at')`), same as
`dentalRecords()`.

**Current state is derived, not stored.** A tooth's current condition
is simply its most recent entry: since `toothConditions` is already
ordered newest-first, the current condition for a given
`tooth_number` is the first entry in the list matching that number. No
separate "current condition" column to keep in sync, no update path
needed for it.

`created_by` is excluded from `ToothCondition::$fillable`; the
controller sets it explicitly from `auth()->id()` after validation.

## `ToothConditionController::store()`

`POST /patients/{patient}/tooth-conditions`:

1. Validates:
   - `tooth_number`: required, integer, between 1 and 32
   - `condition`: required, `Rule::in(ToothCondition::CONDITIONS)`
   - `notes`: nullable string
   - `provider_id`: nullable, `exists:providers,id`
   - `appointment_id`: nullable, `Rule::exists('appointments', 'id')->where('patient_id', $patient->id)` —
     rejects an appointment ID that exists but belongs to a different
     patient
2. Unlike `DentalRecordController::store()`, there is no "at least one
   clinical field" check — `condition` is always required, so every
   valid submission already has content.
3. Creates the `ToothCondition` via `$patient->toothConditions()->make($validated)`,
   sets `created_by = $request->user()->id` directly (never
   mass-assigned), saves.
4. Redirects back (`return back()`), matching `DentalRecordController::store()`.

## `PatientController::show()` additions

Adds a `toothConditions` prop, same map-to-array shape as the existing
`dentalRecords` prop:

```php
'toothConditions' => $patient->toothConditions()
    ->with(['provider', 'appointment', 'creator'])
    ->get()
    ->map(fn (ToothCondition $c) => [
        'id' => $c->id,
        'tooth_number' => $c->tooth_number,
        'condition' => $c->condition,
        'notes' => $c->notes,
        'provider_name' => $c->provider?->name,
        'appointment_start_time' => $c->appointment?->start_time?->toIso8601String(),
        'created_at' => $c->created_at->toIso8601String(),
        'creator_name' => $c->creator->name,
    ]),
```

`providers` and `appointments` props are unchanged and reused as-is —
no new data loading.

## Patients/Show page — Dental Chart tab

Third tab alongside Overview and Dental Records:

```
Patient header (name)
├── [Overview] [Dental Records] [Dental Chart]   ← tabs
│
└── Dental Chart tab
    Two rows of 16 numbered, clickable tooth boxes, clinical horseshoe
    layout (not straight numeric order), so teeth that sit opposite
    each other in the mouth line up vertically:

      Upper:   1  2  3  4  5  6  7  8   9 10 11 12 13 14 15 16
      Lower:  32 31 30 29 28 27 26 25  24 23 22 21 20 19 18 17

    Each box is color-coded by that tooth's current condition (the
    newest toothConditions entry for that tooth_number, computed
    client-side: first entry per tooth_number, since the prop is
    already newest-first). A tooth with no history uses a distinct
    neutral/unmarked style — separate from the "healthy" color, since
    "healthy" is an explicit logged finding.

    A compact fixed legend below the chart maps each condition color
    to its name.

    ↓ clicking a tooth opens a modal/panel:
      - Selected tooth number
      - History: every entry for that tooth, newest first, same card
        style as the Dental Records list (condition · provider name
        (or "—") · linked appointment date (if any); notes; "Logged by
        <user.name> on <date>")
      - + Add Entry form: condition (required <select>), notes
        (optional), provider (optional <select>, reuses the existing
        providers prop), link to appointment (optional <select>,
        reuses the existing appointments prop, this patient's
        appointments only)

    Submits to POST tooth-conditions.store. On success, closes the
    modal/panel; the standard Inertia form POST already refreshes page
    props, so both the tooth's history and its chart color update
    without any extra reload logic.
```

No edit or delete affordance anywhere on an entry — same as dental
records, enforced by there being no endpoint to call.

## Testing

New `tests/Feature/ToothConditionTest.php`:

- **Auth** — an unauthenticated `POST /patients/{patient}/tooth-conditions`
  redirects to login.
- **Creation** — a valid submission creates a `ToothCondition` with the
  submitted fields; `created_by` is the authenticated user's ID even
  when the request body includes a different `created_by` value.
- **Validation** — `tooth_number` outside 1-32 is rejected; `condition`
  outside `ToothCondition::CONDITIONS` is rejected; a missing
  `condition` is rejected.
- **Provider/appointment optional** — an entry can be created with both
  null; an entry can be created with a valid `provider_id` and/or
  `appointment_id`.
- **Appointment ownership** — an `appointment_id` belonging to a
  *different* patient is rejected with a validation error; no entry is
  created.
- **Current-state resolution** — two entries for the same tooth number,
  created in sequence; the `toothConditions` prop returned by
  `patients.show` lists both, newest first, proving current state is
  derivable as the first match per tooth rather than stored
  separately.
- **Show page scoping** — `toothConditions` for one patient does not
  include another patient's entries.
- **No edit/delete routes** — asserts no route exists named
  `tooth-conditions.update` / `tooth-conditions.destroy`.

## Out of scope / explicitly not addressed here

Per-surface (mesial/distal/occlusal/buccal/lingual) charting, treatment
plans, prescriptions, document/X-ray attachments, and any
dentist-role-scoped workspace — all remain deferred to their own future
sub-projects/phases per `docs/PLATFORM_VISION.md`. Also deliberately
not included: editing or deleting a `ToothCondition` (by design, same
rationale as `DentalRecord`), FDI/Palmer notation support, filtering or
pagination of a tooth's history, and any change to existing
patient/appointment/dental-record behavior.
