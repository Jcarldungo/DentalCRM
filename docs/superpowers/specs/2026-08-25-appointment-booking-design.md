# DentalCRM — Phase 3: Public Appointment Request Flow — Design

Status: approved by user, 2026-08-25.

## Purpose

Add a "Request an Appointment" flow to the public website. This is Phase 3
of the platform vision in
[`docs/PLATFORM_VISION.md`](../../PLATFORM_VISION.md) (§27: Availability,
Booking, Calendar, Appointment statuses, Notifications) — scoped down to
what's actually buildable now, the same way Phase 2 scoped down the public
site. See that doc for the full future roadmap, none of which beyond this
slice is in scope here.

Guests can:
- Select a service
- Optionally select a dentist
- Select a preferred date
- Select a preferred time of day (Morning or Afternoon)
- Provide their contact information
- Submit an appointment request

Staff review requests from the existing internal Appointments page and
either confirm or decline them. There is no real-time slot locking, no
live per-slot availability grid, and no patient accounts — the guest's
preferred date/time-of-day is a preference staff act on, not a binding
reservation.

## Product distinction

This phase does not add a fourth experience — it extends two that already
exist:

- **Public website** (Phase 2) — gains one new page, `/book`, plus CTA
  changes on existing pages. Still no auth.
- **Internal staff application** (existing) — gains a "Requests" panel on
  the existing Appointments page. No new nav item, no new page.

Explicitly deferred (per the vision doc, not gaps in this phase): live
per-slot availability, real-time slot locking, holiday/break-period
awareness, email/SMS confirmations or reminders, patient
accounts/portal, guest cancellation or rescheduling.

## Constraints

- No real-time slot locking
- No live availability grid
- No patient accounts
- No email/SMS notifications
- No guest cancellation/rescheduling
- No new `appointment_requests` table and no new appointment-related nav
  section — a request is just an `Appointment` row without a real time yet

## Architecture

Same Laravel app, no new packages. New public route plus one route-less
change (extending the existing `AppointmentController@index` response):

```
routes/web.php
  GET  /book    PublicSiteController@book   name: book        (public)
  POST /book    BookingController@store      name: bookings.store
                 (public, throttle:6,1 — same abuse guard as /contact)

  auth group (existing, unchanged):
  GET   /appointments   AppointmentController@index  — now also returns
                          pending requests (status = 'requested')
  PATCH /appointments/{appointment}  AppointmentController@update — reused
                          as-is for both Confirm and Decline (no new route)
```

```
app/Http/Controllers/BookingController.php        store (public)
app/Http/Controllers/PublicSiteController.php     + book()

resources/js/Pages/Public/Book.jsx                new booking page
resources/js/Pages/Appointments/Index.jsx         + Requests panel
```

## Data model

Extend the existing `Appointment` model/table — no new table.

**Statuses** — `Appointment::STATUSES` grows from 4 to 6:
```
requested   (new)
scheduled
completed
cancelled
no_show
declined    (new)
```

**Existing scheduling fields become nullable**, since a requested
appointment doesn't have a real time yet:
```
start_time    nullable
end_time      nullable
provider_id   nullable
type          nullable
```

For a newly requested appointment: `start_time`/`end_time` are `NULL`;
`provider_id` is populated only if the guest picked a dentist, otherwise
`NULL` ("no preference"); `type` is `NULL` until staff assign one while
confirming.

**New nullable columns:**
```
service_interest       string   e.g. "Root Canal Treatment"
preferred_date          date     e.g. 2026-09-02
preferred_time_of_day   string   "morning" | "afternoon" only
```

`AppointmentController@events` (the FullCalendar feed) needs no code
change — its `whereBetween('start_time', ...)` query naturally excludes
rows where `start_time` is `NULL`, so requests never appear on the
calendar until confirmed.

## Public booking flow — `/book`

Form fields, in order:

1. **Service** — required. Populated from `resources/js/Data/services.js`
   (the same source `Services.jsx` already uses — no duplicated service
   data).
2. **Dentist** — optional. Populated from `resources/js/Data/dentists.js`
   plus a leading "No preference" option.
3. **Preferred date** — required. The date input must reject days the
   clinic isn't open. Derive closed days from `CLINIC.hours` (exported by
   `PublicLayout.jsx`) rather than hardcoding "no Sundays" separately, so
   this stays correct if clinic hours ever change.
4. **Preferred time of day** — required, exactly two options: Morning /
   Afternoon (matching the clinic's actual operating hours — there's no
   evening slot to offer).
5. **Contact information** — Name, Email, Phone required; Notes optional.

On submit, `BookingController@store`:
1. Validates the request.
2. Normalizes the submitted email (lowercase/trim) and looks up an
   existing `Patient` by that normalized email, case-insensitively —
   `John@example.com`, `john@example.com`, and `JOHN@EXAMPLE.COM` must all
   resolve to the same patient rather than creating duplicates. Reuses the
   match if found, otherwise creates a new `Patient`.
3. Creates an `Appointment` with `status: 'requested'`, `start_time` and
   `end_time` left `NULL`, `provider_id` set only if a dentist was chosen,
   `service_interest` set to the selected service's name, and
   `preferred_date`/`preferred_time_of_day` set from the form.

The form collects a single "Name" field (matching the existing Contact
form), but `Patient` has separate `first_name`/`last_name` columns. When
creating a new patient, split on the first space: everything before it is
`first_name`, everything after is `last_name` (empty string if there's no
space) — the same simple convention, applied consistently, that a future
reader can rely on without re-deriving it.

### Success state

After successful submission, show a thank-you panel using the same UX
pattern as the existing Contact page (replaces the form rather than
resetting it in place, structurally preventing double submission).

Wording must not imply the appointment is confirmed — staff still have to
act on it. Use **"Appointment request submitted"**, never "Appointment
confirmed." The panel should make clear staff will review the request.

## Existing website CTA changes

- **`ServiceCard`**'s "Inquire about this" link changes from
  `/contact?service=<name>` to `/book?service=<name>` — booking is the
  more specific action for "I'm interested in this service." Uses the
  existing `service` prop already passed to the card; no new service data.
- **Navigation** (`PublicLayout.jsx`) gains a "Book an Appointment" link
  pointing to `/book`, alongside the existing links — no nav item is
  removed. Contact remains reachable exactly as it is today.
- **Home hero** gains a prominent "Book an Appointment" CTA pointing to
  `/book`. The existing Contact page and its CTA remain unchanged for
  general inquiries.

## Staff review — existing Appointments page

No new navigation item and no new page. `AppointmentController@index`'s
existing Inertia response is extended to also include pending requests
(`Appointment::where('status', 'requested')`), alongside what it already
returns.

A **Requests panel** is added to `Appointments/Index.jsx`, showing per
request: patient name, service (`service_interest`), preferred date,
preferred time of day, dentist preference (or "No preference"), notes,
and contact info where appropriate — with **Confirm** and **Decline**
actions.

**Confirm** reuses the existing appointment update workflow — no new
route. Staff open the existing appointment edit/update form and supply
the real `start_time`, `end_time`, `provider` (if not already set by the
guest), and `type`. Submitting sends the existing
`PATCH /appointments/{id}`, setting `status: 'scheduled'`. The guest's
preferred date/time-of-day was never itself the appointment time — it's
what staff work from to pick the real one:

```
Guest preference → requested → staff assigns actual schedule → scheduled
```

**Decline** is a one-click action: `PATCH /appointments/{id}` with
`status: 'declined'`. The request then no longer appears in the pending
Requests panel. No email/SMS notification is sent.

## Scheduling integrity

Two guards are added to `AppointmentController` as part of this phase.
Both live in the staff-facing controller, not the public booking one — a
guest request has no real time yet, so neither applies at request time.

### Double-booking prevention

`store` and `update` currently validate only that `end_time` is after
`start_time`, so nothing stops two appointments for the same dentist at
the same time. That is a pre-existing gap, but this phase is where it
starts to bite: confirming a request *is* the act of choosing a time, so
the guard belongs here.

An appointment conflicts when, for the **same `provider_id`**, its
half-open interval `[start_time, end_time)` overlaps an existing one:

```
existing.start_time < new.end_time AND existing.end_time > new.start_time
```

Half-open is deliberate — an appointment ending at 09:30 and the next
starting at 09:30 do **not** conflict.

Excluded from the conflict query:
- The appointment being updated itself (so a no-op save isn't a conflict)
- Rows with `start_time IS NULL` (pending requests hold no slot)
- Statuses that free the slot: `cancelled`, `declined`, `no_show`

On conflict, fail validation on `start_time` with a message naming the
clash, rather than throwing — staff should see it inline on the form like
any other validation error.

### Confirm-transition guard

Because `start_time`, `end_time`, `provider_id`, and `type` are now
nullable, a request could be flipped to `scheduled` while still missing a
time. That fails quietly and badly: the FullCalendar feed filters on
`whereBetween('start_time', ...)`, so such an appointment looks confirmed
to staff but never appears on the calendar.

So: whenever `update` sets `status` to `scheduled`, all four of
`start_time`, `end_time`, `provider_id`, and `type` must be present —
either already on the record or supplied in the same request. Otherwise
validation fails. A `scheduled` appointment is therefore always a
complete, visible one.

## Testing

New `tests/Feature/BookingTest.php`:
- **Guest submission** — a guest can submit a valid request; the
  resulting `Appointment` has `status: 'requested'`; `start_time` and
  `end_time` remain `NULL`; the submitted service/date/time-of-day persist
  correctly.
- **Validation** — missing service, missing date, invalid date, a Sunday
  date, an invalid time-of-day value, missing name, invalid email, missing
  phone all produce validation errors.
- **Patient matching** — an existing patient with a matching email is
  reused (no duplicate created); no matching email creates a new patient;
  matching is case-insensitive (`John@example.com` ≡ `john@example.com` ≡
  `JOHN@EXAMPLE.COM`).

Extend the existing `tests/Feature/AppointmentTest.php` (which already
covers create/reschedule/status-update/events-feed/auth for the staff
flow — add to it rather than starting a new file):
- **Confirmation** — a `requested` appointment, `PATCH`-updated with real
  `start_time`/`end_time`/`provider_id`/`type`, ends up `scheduled` with
  those fields persisted correctly.
- **Decline** — a `requested` appointment `PATCH`-updated to `declined`
  ends up in that state and is excluded from the pending-requests query.
- **Confirm-transition guard** — flipping a request to `scheduled` without
  supplying a time fails validation and leaves the record untouched.
- **Double-booking** — overlapping appointments for the same provider are
  rejected; back-to-back ones (09:00–09:30 then 09:30–10:00) are allowed;
  the same time for a *different* provider is allowed; a `cancelled` /
  `declined` / `no_show` appointment does not block its old slot; and
  saving an appointment over itself unchanged is not a conflict.

## Out of scope / explicitly not addressed here

Live per-slot availability, real-time slot locking, holiday awareness,
break-period awareness, email confirmations, SMS confirmations, patient
accounts, patient portal, guest cancellation, guest rescheduling,
automated reminders, a separate `appointment_requests` table, a new
appointment-related navigation section. All remain deferred to later
phases of `docs/PLATFORM_VISION.md`, not gaps in this one.

Also deliberately not included, despite being adjacent: general
status-transition rules (e.g. forbidding `completed` → `requested`).
Only the one transition this phase can actually break — into `scheduled`
— is guarded. Broader state-machine enforcement is a separate change.
