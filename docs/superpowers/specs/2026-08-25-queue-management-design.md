# DentalCRM — Phase 5: Front-Desk Queue — Design

Status: approved by user, 2026-08-25.

## Purpose

Add a front-desk queue for managing today's patients as they move through
the clinic. This is the remaining gap in Phase 5 of the platform vision in
[`docs/PLATFORM_VISION.md`](../../PLATFORM_VISION.md) (§8, §15: Internal
Clinic Dashboard, Queue Management) — v1 already shipped the rest of that
phase (dashboard, patient management, appointment management). See that
doc for the full future roadmap; nothing beyond this slice is in scope
here.

Staff can:
- See today's appointments grouped by where each patient is in the visit
- Check a scheduled patient in on arrival
- Start treatment for a waiting patient
- Mark a patient's treatment complete
- Mark a scheduled patient a no-show
- Add a walk-in patient directly into the waiting line

## Constraints

- The existing `Appointment` model/table is the single source of truth —
  no new table, no new `queue_entries`/`appointment_requests` table.
- No queue-number field (no "A-024") — ordering is by `start_time`.
- No WebSockets, Laravel broadcasting, Redis pub/sub, or other real-time
  infrastructure — the board refreshes via Inertia partial reload on a
  15-second timer.
- No dashboard changes, no per-provider/room filtering, no
  duration-by-appointment-type configuration, no patient portal, no new
  email/SMS.
- No new appointment-status-management endpoints — queue actions reuse
  the existing `PATCH /appointments/{appointment}` update endpoint.

## Architecture

Same Laravel app, no new packages, no migration. One new controller, one
new route pair, one new Inertia page, a nav link, and two new entries in
`Appointment::STATUSES`.

```
routes/web.php (auth group, alongside the existing appointment routes)
  GET  /queue              Admin\QueueController@index         name: queue.index
  POST /queue/walk-ins     Admin\QueueController@storeWalkIn    name: queue.walkins.store

  unchanged, reused by the queue UI:
  PATCH /appointments/{appointment}   Admin\AppointmentController@update
```

```
app/Http/Controllers/Admin/QueueController.php   new
app/Models/Appointment.php                       + 2 statuses

resources/js/Pages/Queue/Index.jsx                new
resources/js/Layouts/AuthenticatedLayout.jsx      + nav link
```

## Data model

Extend `Appointment::STATUSES` from 6 to 8, inserting two new statuses
between `scheduled` and `completed`:

```
requested
scheduled
checked_in     (new)
in_treatment   (new)
completed
cancelled
no_show
declined
```

Lifecycle this phase adds:

```
scheduled → checked_in → in_treatment → completed
scheduled → no_show
```

No column changes. `status` is already a plain string validated by
`Rule::in(Appointment::STATUSES)` in `AppointmentController::update()` —
adding two values to that constant is the entire schema change.

**`checked_in` and `in_treatment` are deliberately excluded from
`SLOT_FREEING_STATUSES`.** That constant marks statuses whose appointment
no longer occupies its provider slot (`cancelled`, `declined`,
`no_show`). A checked-in or in-treatment patient still occupies their
slot, so both `Appointment::hasConflict()` and
`Appointment::countBookedForSlot()` continue to correctly block a second
booking against that time without any change to either method.

## Queue page — `/queue`

`Admin\QueueController@index` renders `Queue/Index`, scoped strictly to
today (`start_time` between `now()->startOfDay()` and
`now()->endOfDay()`), split into four columns:

| Column | Filter | Action(s) | Order |
|---|---|---|---|
| Today's Schedule | `status = scheduled` | Check In, No-show | `start_time` asc |
| Waiting | `status = checked_in` | Start Treatment | `start_time` asc |
| Now Serving | `status = in_treatment` | Complete | `start_time` asc |
| Completed | `status = completed` | — (read-only) | `start_time` asc |

Each card shows patient name, appointment time, type, and provider name —
the same information already surfaced on the Appointments calendar's
event `extendedProps`, reused here rather than inventing a new shape.

No queue-number field. Walk-ins are created with `start_time = now()`
(see below), so they sort into their true arrival position in Today's
Schedule/Waiting alongside pre-scheduled patients without any extra
bookkeeping.

### Actions reuse the existing update endpoint

Every queue action — Check In, Start Treatment, Complete, No-show — is a
`PATCH /appointments/{appointment}` with just `{ status: '<new status>' }`,
exactly the call the Appointments calendar already makes for status
edits. `QueueController` does not implement a parallel
status-transition system; `AppointmentController::update()` is unchanged
and its existing validation, conflict re-check, and
`notifyPatientOfRequestOutcome()` guard all continue to apply unmodified
(that guard only fires when `originalStatus === 'requested'`, so none of
the queue transitions above trigger an email — consistent with today's
behavior).

## Walk-ins

An "Add Walk-in" button on the queue page opens a modal reusing the same
patient/provider/type fields and `<select>` markup as the existing
appointment-create modal on `Appointments/Index.jsx` (no new field is
invented beyond what `Appointment` already supports).

Submitting posts to `POST /queue/walk-ins`
(`QueueController::storeWalkIn`), which:

1. Validates `patient_id`, `provider_id`, and `type` (same rules as
   `AppointmentController::store`).
2. Computes `start_time = now()`, `end_time = now()->addMinutes(30)` — a
   fixed 30-minute block. There is no duration-by-type configuration in
   the codebase today, and adding one is out of scope for this phase.
3. Calls `Appointment::hasConflict($providerId, $start, $end)` — the same
   method `AppointmentController` already uses — and fails validation on
   `provider_id` (mirroring `assertNoConflict()`'s message) if the
   provider already has an overlapping appointment. No appointment is
   created on conflict.
4. Creates the `Appointment` with `status: 'checked_in'`, landing the
   walk-in directly in Waiting (it never passes through Today's
   Schedule, since it was never pre-scheduled).

## Live updates

`Queue/Index.jsx` polls every 15 seconds with:

```js
router.reload({ only: ['todaysSchedule', 'waiting', 'nowServing', 'completed'] })
```

matching Inertia's partial-reload pattern already used elsewhere in the
app, reloading only the queue's own props rather than the full page.
Polling stops when the component unmounts (`clearInterval` in a
`useEffect` cleanup).

## Navigation

`AuthenticatedLayout.jsx` gains a "Queue" `NavLink`/`ResponsiveNavLink`
immediately after "Appointments", following the existing
`route('queue.index')` / `route().current('queue.index')` pattern used by
every other nav item. `/queue` sits behind the same `auth` middleware
group as the rest of the staff app — no new authorization concept.

## Testing

New `tests/Feature/QueueTest.php`:

- **Auth** — an unauthenticated request to `GET /queue` and
  `POST /queue/walk-ins` redirects to login, matching the existing
  `/admin` auth behavior.
- **Index scoping** — a `scheduled` appointment today appears in Today's
  Schedule; a `checked_in` one in Waiting; an `in_treatment` one in Now
  Serving; a `completed` one in Completed; appointments outside today's
  date range (yesterday, tomorrow) are excluded from all four; each
  appointment appears in exactly one column; ordering within a column is
  by `start_time` ascending.
- **Transitions via the existing endpoint** — `PATCH
  /appointments/{appointment}` moves `scheduled → checked_in →
  in_treatment → completed` and `scheduled → no_show`; each resulting
  status persists; existing conflict/notification behavior is
  unaffected (asserted via the existing `AppointmentTest` coverage,
  not duplicated here).
- **Walk-in creation** — a valid walk-in creates an `Appointment` with
  `status: 'checked_in'`, `start_time` within a few seconds of `now()`,
  `end_time` 30 minutes after `start_time`, the correct
  patient/provider/type, and it appears in the Waiting column.
- **Walk-in conflict** — a walk-in for a provider with an overlapping
  appointment is rejected with a validation error, and no `Appointment`
  row is created.

## Out of scope / explicitly not addressed here

Dashboard changes, per-provider or per-room queue filtering,
duration-by-appointment-type configuration, queue numbers, WebSockets or
any real-time push infrastructure, a new queue table, a new
conflict-detection mechanism, the patient portal, and any new
email/SMS. All remain deferred to later phases of
`docs/PLATFORM_VISION.md` or are simply not needed for this slice.

Also deliberately not included: general appointment status-transition
enforcement (e.g. forbidding `completed → checked_in`). Per the existing
"Known gaps" note in `CLAUDE.md`, status transitions stay unconstrained
beyond `Rule::in(STATUSES)` — the queue UI only ever *offers* the valid
forward actions; it does not add a state-machine guard to the model or
controller.
