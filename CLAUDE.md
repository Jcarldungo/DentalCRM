# DentalCRM — working notes

Laravel 12 + Inertia 2 + React 18 + Tailwind 3. A dental clinic platform:
a public marketing site plus an internal front-desk CRM. Built as a
sellable/configurable product for real dental clinics — not yet sold to
one, so it currently develops against a fictional stand-in clinic (see
Hard constraints). No real clinic, employer, or client data involved
*yet*; keep clinic identity (name, branding, contact info) data-driven
and swappable rather than hardcoded, since it becomes a real customer's
data eventually.

## Environment (Windows + Herd)

The README's mention of XAMPP is stale — this runs **Laravel Herd**
(PHP 8.4). The Herd `bin` dir differs per machine only by Windows profile
name (`Jann Carl` at home, `JC` at work), so address it through `$HOME`,
which Git Bash resolves on either machine:

```bash
"$HOME/.config/herd/bin/php.bat" artisan test
"$HOME/.config/herd/bin/composer.bat" install
```

`php.bat` alone also works (Herd puts its bin on PATH); bare `composer`
does **not** — a stale XAMPP shim can shadow it, so always use the
`$HOME` path above for Composer. `npm` is on PATH normally. Run
everything from the repo root (`C:\dev\DentalCRM` at home).

To see the app: `php artisan serve` (:8000) plus `npm run dev` (Vite, :5173)
in parallel — or `composer run dev` for both plus queue/logs.

Tests run against **MariaDB** (`dentalcrm_testing`), not SQLite — the
database must exist before `php artisan test` will work.

## Layout conventions

- **Staff-facing controllers** live in `App\Http\Controllers\Admin\`;
  public ones sit at the top level (`PublicSiteController`,
  `InquiryController`). Route names are unprefixed either way
  (`inquiries.index` is the staff page, `inquiries.store` the public POST).
- **Tests are flat**: `tests/Feature/<Name>Test.php`. No subdirectories
  except the framework's `tests/Feature/Auth/`.
- **Public pages**: `resources/js/Pages/Public/` + `PublicLayout.jsx`.
  Staff pages use `AuthenticatedLayout.jsx`. Keep the two layouts and
  their styling entirely separate — the public site's teal/stone palette
  must not leak into the internal app, or vice versa.
- **Static marketing content** lives in `resources/js/Data/*.js` (services,
  dentists, testimonials, faqs) as plain arrays — deliberately not database
  tables, and there's no admin editor for them.
- `CLINIC` (exported from `PublicLayout.jsx`) is the single source of truth
  for clinic name, address, phone, email, and hours on the **public site**.
  Derive from it rather than hardcoding those values anywhere else in the
  frontend. The one exception is `config/clinic.php`'s `contact_phone`/
  `contact_email`, which server-rendered mail (`app/Mail/*`) reads instead,
  since it can't reach into the JS bundle — see that file's comment.

## Hard constraints

- **No SMS sending anywhere.** Mail exists but only via the `log` driver
  (`.env.example`'s `MAIL_MAILER=log`; `phpunit.xml` pins `MAIL_MAILER=array`
  for tests) — real `Mailable`/view code, nothing ever leaves the fictional
  clinic's fake domain. `app/Mail/AppointmentConfirmed.php`,
  `AppointmentDeclined.php` (sent from
  `Admin\AppointmentController::update()`), `AppointmentLookupLink.php`
  (sent from `AppointmentLookupController::send()`), and
  `AppointmentReminder.php` (sent from the
  `appointments:send-reminders` scheduled command — see Planning
  workflow) are the only senders so far. Anything else that would need to
  notify someone still surfaces in-app for staff to act on, unless it
  specifically needs to reach a guest with no account — then follow the
  same pattern.
  `AppointmentLookupLink` alone implements `ShouldQueue` (needed for its
  no-enumeration guarantee — see its docblock), so a queue worker must be
  running for that one link to actually go out; `composer run dev` already
  starts `queue:listen` alongside the app server.
- **The clinic is fictional for now**: "Harborview Dental Clinic", a
  made-up Makati address/phone, and a `.example` email domain. Don't
  introduce a real, resolvable domain — there's no real customer onboarded
  yet, not a permanent rule against ever having one. Dentist profiles use
  initials-avatars, not photos of real-looking people; testimonials are
  first name + last initial.
- **Prices are in Philippine pesos** (`₱`).
- **No roles and no seeded login.** Every authenticated user is an equal
  front-desk staff member. Register at `/register`;
  `db:seed --class=DemoSeeder` creates patients/providers/appointments but
  no user.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump`, no unused
  imports, no commented-out code.

## Planning workflow

Specs and plans are committed to `docs/superpowers/specs/` and
`docs/superpowers/plans/` as `YYYY-MM-DD-<topic>.md`, one pair per phase.
Write the spec, get it approved, then write the plan, then implement
task-by-task with a commit per task.

`docs/PLATFORM_VISION.md` is the long-range roadmap (8 phases) — it's
aspirational, not a contract. Shipped so far:

- **v1** — internal CRM (dashboard, patient management, appointment
  management, most of Phase 1/5).
- **Phase 2** — public website.
- **Phase 3, scoped slice** — public appointment *requests*, specced at
  `docs/superpowers/specs/2026-08-25-appointment-booking-design.md` —
  manual staff confirm/decline, a clinic-wide per-slot capacity cap on
  the booking form, no live per-provider availability, a
  confirm/decline email to the guest, a passwordless status lookup
  (`/my-appointments` — email in, a 30-minute signed link out, no
  accounts), and a day-before reminder email for scheduled appointments
  (`appointments:send-reminders`, run daily at 17:00 clinic time via
  `routes/console.php`'s `Schedule::command` — `composer run dev` runs
  `schedule:work` locally so this actually fires in dev; a real deploy
  needs the standard Laravel cron entry calling `schedule:run` every
  minute) — see Hard constraints. No patient accounts/portal (Phase 4
  proper) exist yet.
- **Phase 5, remaining gap** — the front-desk queue, specced at
  `docs/superpowers/specs/2026-08-25-queue-management-design.md` — a
  `/queue` board (Today's Schedule / Waiting / Now Serving / Completed,
  scoped to today, ordered by `start_time`), check-in / start-treatment
  / complete / no-show actions that all reuse the existing
  `PATCH /appointments/{appointment}` endpoint, walk-in creation (fixed
  30-minute block, lands straight in Waiting), and 15-second polling via
  Inertia partial reload. No queue-number field, no new table — it's
  the `Appointment` model plus two new statuses (`checked_in`,
  `in_treatment`).
- **Phase 6, sub-project 1** — patient detail page + dental records,
  specced at `docs/superpowers/specs/2026-08-26-dental-records-design.md`
  — `/patients/{patient}` (Overview + Dental Records tabs), and an
  append-only `DentalRecord` model (type, examination, diagnosis,
  procedure, notes, optional provider/appointment link) with no
  edit/delete route or UI. This is the page structure the rest of
  Phase 6 (odontogram, treatment plans, prescriptions, dentist
  workspace) attaches to — prescriptions and the dentist workspace
  remain unbuilt.
- **Phase 6, sub-project 2** — dental chart / odontogram, specced at
  `docs/superpowers/specs/2026-08-26-dental-chart-design.md` — a third
  "Dental Chart" tab on `/patients/{patient}` showing all 32 teeth
  (Universal numbering) in a clinical horseshoe layout, color-coded by
  each tooth's current condition (derived client-side as its newest
  entry, not stored); an append-only `ToothCondition` model (tooth
  number, condition, notes, optional provider/appointment link) with no
  edit/delete route or UI, same pattern as `DentalRecord`. No
  per-surface (mesial/distal/etc.) charting.
- **Phase 6, sub-project 3** — treatment plans, specced at
  `docs/superpowers/specs/2026-08-26-treatment-plans-design.md` — a
  fourth "Treatment Plan" tab on `/patients/{patient}` listing every
  proposed treatment for that patient, grouped client-side into Active
  (`planned`/`scheduled`/`in_progress`) and Resolved
  (`completed`/`cancelled`). Unlike `DentalRecord`/`ToothCondition`, the
  `TreatmentPlanItem` model is mutable: `status`, `priority`,
  `estimated_cost`, and `notes` can change via
  `PATCH /patients/{patient}/treatment-plan-items/{treatmentPlanItem}`,
  but `treatment`, `tooth_number`, `provider_id`, and `appointment_id`
  are fixed at creation. No delete, ever, and no grouping/parent
  "Treatment Plan" entity — a patient's plan is just all their
  `TreatmentPlanItem` rows.
- **Phase 6, sub-project 4** — prescriptions, specced at
  `docs/superpowers/specs/2026-08-28-prescriptions-design.md` — a fifth
  "Prescriptions" tab on `/patients/{patient}` listing a patient's
  prescribed medications, grouped client-side into Active and
  Discontinued. One medication per `Prescription` row (no
  header/line-item parent). Clinical content — `medication`, `dosage`,
  `frequency`, `duration`, `quantity`, `instructions`, `provider_id`,
  `appointment_id` — is fixed at creation; the only post-creation change
  is a one-way `active → discontinued` flip via
  `PATCH /patients/{patient}/prescriptions/{prescription}` (the
  discontinue action, which also records `discontinued_at` and an
  optional `discontinued_reason`, and 403s if already discontinued). No
  delete, ever. Nothing is transmitted anywhere — a printable
  prescription slip is a plausible future slice, not built. The three
  shared date/peso formatters were extracted from `Patients/Show.jsx`
  into `resources/js/Pages/Patients/format.js`; the new tab body lives
  in its own `PrescriptionsTab.jsx` component (the other four tab bodies
  remain inline in `Show.jsx`).
- **Phase 6, sub-project 5** — the dentist workspace, specced at
  `docs/superpowers/specs/2026-08-29-dentist-workspace-design.md` — a
  `/workspace` page (`Admin\WorkspaceController@index`, read-only, no
  model/migration) showing one date's `scheduled`/`checked_in`/
  `in_treatment`/`completed` appointments for a chosen provider (or all
  active), ordered by `start_time`. Each row links to
  `/patients/{patient}` and carries badges for that patient's open
  treatment-plan items (`planned`/`scheduled`/`in_progress`) and active
  prescriptions. Provider `<select>` + date input + prev/today/next,
  each re-issuing an Inertia `GET` with `preserveState`. Because the app
  has no roles and no `Provider`↔`User` link, this is a shared view any
  staff member drives — not auto-scoped to a logged-in dentist (that
  needs auth work, deferred). No polling, no inline clinical editing.

## Known gaps

- `Patient::dueForRecall()` loads every patient and their cleaning
  appointments into memory, then filters in PHP. It runs on every dashboard
  load. Fine at demo scale, should become a query.
- `Appointment` status transitions are unconstrained beyond
  `Rule::in(STATUSES)` — any status can become any other.
- `Appointment::countBookedForSlot()` and `hasConflict()` do a full table
  scan (no index on `preferred_date`/`preferred_time_of_day`/`status`/
  `start_time`). Fine at demo scale; add a composite index if the table
  grows.
- Both of those checks are also check-then-act: two concurrent submissions
  can each pass the check and together exceed capacity or double-book a
  provider. Low-risk at this traffic level; would need a transaction +
  row lock to close.
- If `appointments:send-reminders` fails to email one appointment, it's
  never retried — by the next day's run that appointment's `start_time`
  is no longer "tomorrow," so it falls out of the query for good. Same
  risk tolerance as the confirm/decline mail-failure handling (logged,
  not retried).
