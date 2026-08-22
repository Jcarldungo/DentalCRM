# DentalCRM — v1 Design

Status: approved by user, 2026-08-23.

## Purpose

An internal front-desk CRM for a single dental clinic. Personal/portfolio
project — no employer or real client involved, no proprietary data. Chosen
to demonstrate standalone system-architecture skills (domain modeling,
CRUD-heavy admin app, calendar UI) alongside the existing gastos and
WeePlay Therapy Center portfolio pieces.

Audience: front-desk staff and dentists at one clinic. **Internal-only** —
no patient-facing portal, no public booking page, no patient login.

## Scope (v1)

In scope:
- Patient records
- Appointment scheduling (calendar view)
- Recall reminders ("due for a 6-month cleaning")
- Staff login (single role, no admin/staff split)

Deliberately deferred, not built in v1:
- Multi-tenancy (multiple clinics)
- Billing / invoicing
- SMS / email notifications
- Treatment-plan pipeline (consultation → treatment → completed)
- Provider-specific auth roles
- Any patient-facing feature (self-service booking, patient login)

## Architecture

Laravel 12 + Inertia 2 + React 18, Breeze for auth — mirrors the existing
ATS project's stack (same author, same comfort zone, well-suited to a
CRUD-heavy admin app with a calendar UI). MariaDB via XAMPP for local dev.

Layout follows the ATS convention:

```
app/Models/                        Patient, Provider, Appointment
app/Http/Controllers/Admin/        one controller per resource
database/migrations/               patients, providers, appointments, users (Breeze default)
resources/js/Pages/                Inertia pages (Dashboard, Patients, Appointments)
resources/js/Components/           shared UI (Badge, Pagination, etc., reused from ATS pattern)
tests/Feature/                     feature coverage per controller
```

No multi-tenancy: all data belongs to the one clinic implicitly (no
`clinic_id` column anywhere in v1).

## Domain model

- **patients** — name, date of birth, contact info (phone/email),
  emergency contact, free-text chart notes, optional
  `recall_interval_months` override (default 6).
- **providers** — dentists/staff who see patients. Simple lookup table,
  no login of their own required for v1 (front-desk staff book on their
  behalf).
- **appointments** — `patient_id`, `provider_id`, start time, end time,
  type (`checkup` / `cleaning` / `procedure` / `other`), status
  (`scheduled` / `completed` / `cancelled` / `no_show`).
- **users** — front-desk staff, Breeze default, single role.

## Appointment scheduling

FullCalendar (`@fullcalendar/react`) renders a day/week view.

- `AppointmentController@index` returns events as JSON for the calendar
  to render.
- Creating an appointment: click an empty slot → form (patient picker +
  provider + type) → `store()`.
- Rescheduling: drag an event → `update()` with the new start/end time.
- Clicking an event opens a details/edit view.

No conflict-blocking in v1 (double-booking a provider is allowed but
visible on the calendar) — flagged as a candidate follow-up, not built now.

## Recall reminders

A "Due for recall" widget on the dashboard, computed on page load — no
background job, no notification table:

```
for each patient:
  last_cleaning = most recent appointment where type = 'cleaning' and status = 'completed'
  if last_cleaning is null: skip (never had one — not "overdue")
  due_date = last_cleaning.start_time + (patient.recall_interval_months ?? 6) months
  if due_date <= today: show in the list, sorted by how overdue
```

Pure query (`Patient::dueForRecall()` scope or equivalent), no queue, no
external notification service.

## Testing

Feature tests per controller, following `tests/Feature/Ats/`'s pattern in
the ATS repo:
- Patient CRUD
- Appointment CRUD, including reschedule via drag (update endpoint)
- Recall query correctness (patient with no cleaning history excluded;
  patient just inside the interval excluded; patient past it included;
  per-patient interval override respected)

## Deployment

Not decided yet. Build and run locally via XAMPP first, matching the ATS
workflow; pick a host (Railway, Render, or similar) once the app is far
enough along to be worth a live demo link on the portfolio.

## Out of scope / explicitly not addressed here

- No employer or real patient data — this is a from-scratch personal
  project, unrelated to any HRIS/ATS work.
- No appointment-conflict prevention (documented above as a deferred
  follow-up, not a v1 gap that blocks anything).
