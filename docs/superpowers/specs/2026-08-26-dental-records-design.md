# DentalCRM — Phase 6 sub-project 1: Patient Detail Page + Dental Records — Design

Status: approved by user, 2026-08-26.

## Purpose

Phase 6 of `docs/PLATFORM_VISION.md` (§10-13: Dental Chart/Odontogram,
Dental Records, Treatment Plans, Dentist Workspace) bundles five things
that don't belong in one spec. This is the first slice: a place for a
patient's clinical history to live, plus the page structure everything
else in Phase 6 (odontogram, treatment plans, prescriptions) will attach
to later. Nothing else from Phase 6 is in scope here — see "Out of
scope" below.

Patients today have no detail page at all — `Patients/Index.jsx` is a
flat table with create/edit/delete only. Staff can:
- Open a patient's detail page from the patients list
- See the patient's existing profile info (Overview tab)
- See the patient's dental records in chronological (newest-first) order
- Add a new dental record, optionally attributed to a provider and/or
  linked to one of that patient's own appointments

A dental record, once created, cannot be edited or deleted — corrections
are made by adding a new record, not by rewriting history.

## Constraints

- No RBAC. Every authenticated user can create and view dental records,
  same as every other staff feature in the app today.
- No odontogram, treatment plans, or prescriptions in this slice —
  `DentalRecord` is deliberately kept to a clinical-note shape (see Data
  model) so those can attach cleanly later without reshaping this table.
- No attachments/file upload.
- No edit or delete of a `DentalRecord` once created — no route, no
  controller method, no UI for either.
- No dentist workspace / role-scoped view — deferred until roles exist.

## Architecture

Same Laravel app, no new packages. One migration, one model, one
controller (`store` only), one route pair, one new Inertia page, and a
`show()` addition to the existing `PatientController`.

```
routes/web.php (auth group, alongside the existing patient routes)
  GET  /patients/{patient}                 Admin\PatientController@show        name: patients.show
  POST /patients/{patient}/dental-records  Admin\DentalRecordController@store  name: dental-records.store
```

```
database/migrations/..._create_dental_records_table.php   new
app/Models/DentalRecord.php                                new
app/Models/Patient.php                                     + dentalRecords() relation
app/Http/Controllers/Admin/PatientController.php            + show()
app/Http/Controllers/Admin/DentalRecordController.php       new

resources/js/Pages/Patients/Index.jsx                       patient name links to Show
resources/js/Pages/Patients/Show.jsx                        new
```

## Data model

New `dental_records` table:

| Column | Type | Notes |
|---|---|---|
| `patient_id` | `foreignId` | required, `cascadeOnDelete()` — matches `appointments.patient_id`; deleting a patient removes their dental records |
| `provider_id` | `foreignId` nullable | `nullOnDelete()` — deleting a provider preserves the record, just clears attribution |
| `appointment_id` | `foreignId` nullable | `nullOnDelete()` — deleting an appointment preserves the record |
| `type` | `string` | one of `consultation`, `procedure`, `follow_up`, `other` |
| `examination` | `text` nullable | |
| `diagnosis` | `text` nullable | |
| `procedure` | `text` nullable | |
| `notes` | `text` nullable | |
| `created_by` | `foreignId` → `users` | required, set server-side only, never mass-assigned |
| `created_at` | `timestamp` | `useCurrent()` |

No `updated_at` column — append-only by construction, not just by
convention. `DentalRecord` sets `const UPDATED_AT = null;` so Eloquent
never touches a column that doesn't exist.

`DentalRecord::TYPES` is a `const` array (`['consultation', 'procedure',
'follow_up', 'other']`), the same pattern as `Appointment::STATUSES`,
used by both the store validation and the frontend `<select>`.
`DentalRecord` defines `provider()` (`belongsTo(Provider::class)`),
`appointment()` (`belongsTo(Appointment::class)`), and `creator()`
(`belongsTo(User::class, 'created_by')`).

`Patient::dentalRecords(): HasMany` orders newest-first at the
relationship definition (`->latest('created_at')`), so every consumer —
the Show page today, anything else later — gets correct ordering for
free instead of re-sorting in the controller or the UI.

`created_by` is excluded from `DentalRecord::$fillable`; the controller
sets it explicitly from `auth()->id()` after validation, so a
client-supplied `created_by` in the request body is silently ignored,
never trusted.

## `PatientController::show()`

Renders `Patients/Show` with:
- `patient` — the route-bound `Patient`
- `dentalRecords` — `$patient->dentalRecords()->with(['provider', 'creator'])->get()`
- `providers` — `Provider::orderBy('name')->get()`, for the New Record
  form's provider `<select>`. Unfiltered by `active`, unlike the queue's
  walk-in form — an inactive provider may still be the correct
  attribution for a record about past treatment, and the store
  validation (`exists:providers,id`) doesn't require `active` either
- `appointments` — `$patient->appointments()->orderByDesc('start_time')->get()`,
  for the optional "link to appointment" `<select>`, scoped to this
  patient only

## `DentalRecordController::store()`

`POST /patients/{patient}/dental-records`:

1. Validates:
   - `type`: required, `Rule::in(DentalRecord::TYPES)`
   - `provider_id`: nullable, `exists:providers,id`
   - `appointment_id`: nullable, `Rule::exists('appointments', 'id')->where('patient_id', $patient->id)` —
     rejects an appointment ID that exists but belongs to a different
     patient, not just one that doesn't exist at all
   - `examination`, `diagnosis`, `procedure`, `notes`: nullable strings
   - A closure/`after()` rule requiring at least one of
     `examination`/`diagnosis`/`procedure`/`notes` to be non-empty after
     `trim()` — an all-whitespace submission is rejected the same as an
     all-empty one
2. Creates the `DentalRecord` via `$patient->dentalRecords()->create([...])`
   with `created_by` set to `auth()->id()`, never from request input.
3. Redirects back (matches the existing `PatientController::store`/`update`
   pattern — `return back();`).

## Patients/Show page — `/patients/{patient}`

```
Patient header (name)
├── [Overview] [Dental Records]   ← tabs
│
├── Overview tab
│   Read-only display of the existing profile fields (contact, DOB,
│   emergency contact, notes, recall interval).
│   [Edit] button opens the same modal component Patients/Index.jsx
│   already uses for editing, submitting to the existing
│   patients.update route — no new edit flow, no duplicated form.
│
└── Dental Records tab
    [+ New Record] button
    ↓ modal: type, provider (optional), link-to-appointment (optional,
      this patient's appointments only), examination, diagnosis,
      procedure, notes
    ↓ list, newest first, each entry showing:
      - type · provider name (or "—") · linked appointment date (if any)
      - examination / diagnosis / procedure / notes (whichever are set)
      - created date + creator name ("Logged by <user.name> on <date>")
```

No edit or delete affordance anywhere on a record — the append-only rule
is enforced by there being no endpoint to call, not just by hiding a
button.

`Patients/Index.jsx` changes only enough to link into this: each row's
patient name becomes a link to `patients.show`. The existing Edit/Delete
buttons on that row are unchanged.

## Testing

New `tests/Feature/DentalRecordTest.php`:

- **Auth** — an unauthenticated request to `GET /patients/{patient}` and
  `POST /patients/{patient}/dental-records` redirects to login.
- **Creation** — a valid submission creates a `DentalRecord` with the
  submitted fields; `created_by` is the authenticated user's ID even
  when the request body includes a different `created_by` value (proves
  it's ignored, not just unset).
- **Provider/appointment optional** — a record can be created with both
  null; a record can be created with a valid `provider_id` and/or
  `appointment_id`.
- **Appointment ownership** — an `appointment_id` belonging to a
  *different* patient is rejected with a validation error; no record is
  created.
- **Clinical-content validation** — a submission where
  `examination`/`diagnosis`/`procedure`/`notes` are all null, all empty
  strings, or all whitespace-only is rejected; a submission with only
  one of the four populated succeeds.
- **Show page** — `patients.show` returns the patient's dental records
  ordered newest-first, and does not include another patient's records.
- **No edit/delete routes** — asserts no route exists named
  `dental-records.update` / `dental-records.destroy` (guards against
  the append-only constraint silently regressing).

## Out of scope / explicitly not addressed here

Odontogram, treatment plans, prescriptions, document/X-ray attachments,
and any dentist-role-scoped workspace — all remain deferred to their own
future sub-projects/phases per `docs/PLATFORM_VISION.md`. Also
deliberately not included: editing or deleting a `DentalRecord` (by
design, not as a gap to fill later within this same shape — a future
correction/amendment feature, if ever needed, would be a new record type
or a separate mechanism, not an update to this table), record filtering/
search, pagination of the dental records list, and any change to
`Patient::dueForRecall()` or other existing patient/appointment
behavior.
