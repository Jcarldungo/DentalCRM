# DentalCRM — Phase 6 sub-project 5: Dentist Workspace — Design

Status: approved by user, 2026-08-29.

## Purpose

Phase 6 of `docs/PLATFORM_VISION.md` (§13 Dentist Workspace) is the last
unbuilt slice of Phase 6. §13 asks for "a dentist-focused workflow"
optimized for speed — today's patients, fast access to a patient's
clinical context, minimal administrative navigation.

The app has **no roles and no `Provider`↔`User` link** — every
authenticated user is equal front-desk staff, and a `Provider` (the
dentist) is a separate record with no login. A literal "the logged-in
dentist sees their own day" is therefore not buildable without an auth
change, which is explicitly out of scope (see Constraints). Instead this
slice ships the workspace as a **shared per-provider clinical-prep
view** any staff member can open:

- Open `/workspace`
- Pick a provider (or "All providers") and a date (default: today)
- See that provider's appointments for the day as a dense,
  time-ordered list
- Each row shows clinical-readiness badges — how many open
  treatment-plan items and active prescriptions that patient has — so
  the dentist knows at a glance who has pending work before they sit
  down
- Click a patient to open their existing detail page
  (`/patients/{patient}`) with its Overview + four clinical tabs

There is no inline clinical editing in the workspace — it is a
navigation and situational-awareness surface, not a second place to
write records.

## Constraints

- **No auth changes.** No roles/RBAC, no `provider_id` on `users`, no
  `Provider`↔`User` link. Every authenticated user can open the
  workspace and pick any provider, same as every other staff feature.
- **No new model, no migration.** The workspace is a read-only
  projection over existing `Appointment`, `Provider`, `Patient`,
  `TreatmentPlanItem`, and `Prescription` data.
- **No inline clinical editing.** No "add note" / "new prescription" /
  "discontinue" from the workspace — those live on
  `/patients/{patient}` and its tabs, unchanged.
- **Strictly the selected day.** The list is one date's scheduled
  appointments for the chosen provider — not "all this provider's
  patients ever", not a rolling window.
- **No real-time polling.** Unlike `/queue` (15s Inertia reload), the
  workspace is a working view the user actively drives; it reloads only
  on a control change. Adding polling later is a possible enhancement,
  not this slice.
- **No documents / X-rays, no follow-up scheduling, no
  medical-alert/allergy or outstanding-balance data** on the rows —
  none of those models exist yet. A follow-up appointment is still
  created on the existing `/appointments` calendar.

## Architecture

Same Laravel 12 + Inertia 2 + React 18 app, no new packages. One
controller (`index` only), one route, one Inertia page, two nav-link
additions. No model, no migration, no factory.

```
routes/web.php (auth group)
  GET /workspace   Admin\WorkspaceController@index   name: workspace.index
```

```
app/Http/Controllers/Admin/WorkspaceController.php   new
resources/js/Pages/Workspace/Index.jsx               new
resources/js/Layouts/AuthenticatedLayout.jsx         + "Workspace" NavLink (desktop) + ResponsiveNavLink (mobile), after "Queue"
```

## `WorkspaceController::index(Request $request)`

`GET /workspace?provider_id=&date=`

1. **Validate:**
   - `provider_id`: `nullable`, `exists:providers,id`
   - `date`: `nullable`, `date`

   A bad `provider_id` or unparseable `date` is a 422 with session
   errors, matching the rest of the app. (In practice the frontend only
   ever sends a valid provider id from the `<select>` and a
   `<input type="date">` value.)

2. **Resolve inputs:**
   - `$date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();`
     (uses the app's default timezone, consistent with every other
     `now()`/`today()` in the codebase)
   - `$providerId = $request->input('provider_id');` — may be `null`,
     meaning "All providers"

3. **Providers for the `<select>`:**
   `Provider::where('active', true)->orderBy('name')->get(['id', 'name'])`
   — active only, same as the queue's walk-in form. A day may still
   contain an appointment for a now-inactive provider; that appointment
   is not hidden (the query below does not filter provider `active`),
   the provider just isn't a pick option.

4. **The day's appointments:**
   ```php
   $appointments = Appointment::query()
       ->with('patient:id,first_name,last_name,date_of_birth')
       ->whereDate('start_time', $date)
       ->whereIn('status', ['scheduled', 'checked_in', 'in_treatment', 'completed'])
       ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
       ->orderBy('start_time')
       ->orderBy('id')
       ->get();
   ```
   The status set matches `QueueController::index()` exactly:
   `requested` (no `start_time` — unscheduled) is excluded by both the
   status filter and `whereDate('start_time', …)`; `cancelled`,
   `declined`, and `no_show` are excluded as not part of a prep view.
   The `->orderBy('id')` is a stable tiebreak for two appointments at
   the same `start_time`.

5. **Badge counts** — one grouped query each, over the distinct
   `patient_id`s in the day's list (empty list ⇒ skip / empty maps):
   ```php
   $patientIds = $appointments->pluck('patient_id')->unique()->values();

   $openTreatments = TreatmentPlanItem::query()
       ->whereIn('patient_id', $patientIds)
       ->whereIn('status', ['planned', 'scheduled', 'in_progress'])
       ->selectRaw('patient_id, COUNT(*) as aggregate')
       ->groupBy('patient_id')
       ->pluck('aggregate', 'patient_id');

   $activePrescriptions = Prescription::query()
       ->whereIn('patient_id', $patientIds)
       ->where('status', 'active')
       ->selectRaw('patient_id, COUNT(*) as aggregate')
       ->groupBy('patient_id')
       ->pluck('aggregate', 'patient_id');
   ```
   The `planned`/`scheduled`/`in_progress` set is the same "Active"
   grouping the Treatment Plan tab uses (`ACTIVE_TREATMENT_STATUSES` in
   `Patients/Show.jsx`). `active` is the only non-discontinued
   `Prescription` status.

6. **Render** `Inertia::render('Workspace/Index', [...])` with:
   - `providers` — `[{id, name}]`, active, name-ordered
   - `selectedProviderId` — `$providerId` cast to `int` or `null` (so
     the frontend `<select>` can match option values)
   - `date` — `$date->toDateString()` (`Y-m-d`)
   - `appointments` — the mapped rows:
     ```php
     $appointments->map(fn (Appointment $a) => [
         'id' => $a->id,
         'patient_id' => $a->patient_id,
         'patient_name' => $a->patient->full_name,
         'patient_age' => $a->patient->date_of_birth
             ? (int) $a->patient->date_of_birth->diffInYears(now())
             : null,
         'type' => $a->type,
         'status' => $a->status,
         'start_time' => $a->start_time->toIso8601String(),
         'end_time' => $a->end_time?->toIso8601String(),
         'notes' => $a->notes,
         'open_treatment_count' => (int) ($openTreatments[$a->patient_id] ?? 0),
         'active_prescription_count' => (int) ($activePrescriptions[$a->patient_id] ?? 0),
     ])
     ```
     (`Patient::$casts` already makes `date_of_birth` a Carbon date;
     `full_name` is the existing `getFullNameAttribute`.)

No `store`/`update`/`destroy` — the workspace writes nothing.

## `Workspace/Index` page — `/workspace`

`AuthenticatedLayout`, header "Workspace".

```
Controls row:
  [ Provider ▾ ]   ("All providers" + each active provider)
  [ date input ]   type=date, value = date prop
  [ ‹ Prev ]  [ Today ]  [ Next › ]

Friendly date heading:  "Wednesday, 3 September 2026"

List (time-ordered), one row per appointment:
  09:00–09:30 · [scheduled]  · cleaning
  Maria Cruz (34)  → links to /patients/{patient_id}
  [3 open treatments]  [2 active Rx]        ← shown only when count > 0
  "Notes: patient asked about whitening"    ← shown only when notes present

Empty state:  "No appointments for <provider label> on <friendly date>."
```

- **Controls behaviour:** changing the provider `<select>`, the date
  input, or clicking Prev/Today/Next issues
  `router.get(route('workspace.index'), { provider_id, date }, { preserveState: true, preserveScroll: true, replace: true })`.
  `provider_id` is omitted from the params when "All providers" is
  selected. Prev/Next shift `date` by one day, computed client-side;
  Today sets it to the browser's current date.
- **Status badge:** a small colour map local to the page
  (`scheduled` grey, `checked_in` blue, `in_treatment` amber,
  `completed` green) — the same visual language as the other tabs, not
  a shared component (there is no shared status-badge component in the
  app today and this slice does not add one).
- **Treatment badge** amber, **Rx badge** blue, each rendered only when
  its count is `> 0`, pluralised ("1 open treatment" / "3 open
  treatments").
- **Patient link:** `<Link href={/patients/${row.patient_id}}>` — plain
  Inertia navigation to the existing detail page.
- Date formatting via `toLocaleDateString` inline (this page is not a
  `Patients/*` page, so it does not use
  `resources/js/Pages/Patients/format.js`; a one-line formatter here is
  fine and keeps the two areas decoupled).

## Testing

New `tests/Feature/WorkspaceTest.php` (flat, per repo convention):

- **Auth** — a guest `GET /workspace` redirects to login.
- **Renders** — an authenticated `GET /workspace` returns
  `Inertia` component `Workspace/Index` with `providers`, `date`
  (today's `Y-m-d` when no param), `selectedProviderId` (null when no
  param), and `appointments`.
- **Day scoping / ordering** — given appointments on the target date
  and on adjacent dates, only the target date's appear, ordered by
  `start_time` then `id`.
- **`date` param** — `?date=YYYY-MM-DD` returns that day's
  appointments and echoes `date`.
- **`provider_id` filter** — `?provider_id=X` returns only provider
  X's appointments; without it, all providers' appear;
  `selectedProviderId` echoes the int.
- **Status exclusion** — `requested`, `cancelled`, `declined`, and
  `no_show` appointments on the target date are absent; `scheduled`,
  `checked_in`, `in_treatment`, `completed` are present.
- **Badge counts** — a patient with 2 `planned` + 1 `completed`
  treatment-plan items and 1 `active` + 1 `discontinued` prescription
  shows `open_treatment_count` 2 and `active_prescription_count` 1;
  a second patient in the same day with none shows 0/0 (no
  cross-patient leak).
- **Inactive provider** — an inactive provider is not in the
  `providers` list, but that provider's appointment on the day is still
  listed.
- **Validation** — `?provider_id=999999` and `?date=not-a-date` each
  return a validation error.
- **`patient_age`** — a patient with a `date_of_birth` gets an integer
  age; one without gets `null`.

## Out of scope / explicitly not addressed here

- Any roles / RBAC / `Provider`↔`User` link / "my day" auto-scoping —
  deferred; would be its own sub-project (PLATFORM_VISION §21).
- Inline clinical editing of any kind from the workspace.
- Documents / X-rays, follow-up scheduling from the workspace,
  medical-alert / allergy / outstanding-balance row data — no models
  exist; each is a future slice.
- Real-time polling / auto-refresh.
- A shared status-badge component or extraction of the other tabs'
  inline badge markup.
- Walk-ins or any appointment mutation from the workspace (that stays
  on `/queue` and `/appointments`).
- Any change to `Appointment`, `Provider`, `Patient`,
  `TreatmentPlanItem`, `Prescription`, or the existing
  `/patients/{patient}` page.
