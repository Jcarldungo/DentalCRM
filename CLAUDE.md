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

`php` and `composer` are **not on PATH** in the Bash tool. The README's
mention of XAMPP is stale — this machine runs Laravel Herd. Use:

```bash
"/c/Users/JC/.config/herd/bin/php.bat" artisan test
"/c/Users/JC/.config/herd/bin/composer.bat" install
```

`npm` is on PATH normally. Run everything from the repo root
(`C:\dev JC\DentalCRM`).

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
  for clinic name, address, phone, email, and hours. Derive from it rather
  than hardcoding those values anywhere else.

## Hard constraints

- **No SMS sending anywhere.** Mail exists but only via the `log` driver
  (`.env.example`'s `MAIL_MAILER=log`; `phpunit.xml` pins `MAIL_MAILER=array`
  for tests) — real `Mailable`/view code, nothing ever leaves the fictional
  clinic's fake domain. `app/Mail/AppointmentConfirmed.php` and
  `AppointmentDeclined.php` (sent from
  `Admin\AppointmentController::update()`) are the only senders so far.
  Anything else that would need to notify someone still surfaces in-app for
  staff to act on, unless it specifically needs to reach a guest with no
  account — then follow the same pattern.
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
aspirational, not a contract. Shipped so far: v1 (internal CRM), Phase 2
(public website), and a scoped slice of Phase 3 (public appointment
*requests*, specced at
`docs/superpowers/specs/2026-08-25-appointment-booking-design.md`) —
manual staff confirm/decline, a clinic-wide per-slot capacity cap on the
booking form, no live per-provider availability, and a confirm/decline
email to the guest (see Hard constraints).

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
