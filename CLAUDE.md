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
  must not leak into the internal app, or vice versa. The staff app is
  cool `slate` neutrals plus the custom `brand` scale in
  `tailwind.config.js`; the public site is warm `stone` plus `teal-700`.
  `tailwind.config.js` also carries the named `sidebar-*` roles
  (`DEFAULT`/`raised`/`border`/`text`/`muted`) for the one dark surface in
  the app, and `shadow-card` for the one card elevation. Reach for those
  rather than spelling out a near-black hex or a bespoke shadow.
- **Staff UI components** live in `resources/js/Components/UI/` and are
  the only place their concern is implemented. Use them rather than
  hand-rolling: `Button`, `Card`, `Modal`/`ConfirmDialog` (Headless UI —
  never hand-roll an overlay, you lose the focus trap), `Field`/
  `SelectField`/`TextareaField` (they generate the `id` and wire
  `htmlFor`, which is what keeps every input named), `Tabs`, `Toast`,
  `StatusBadge`, `StatTile` (the only tinted surface in the app — a
  headline number, tone chosen for what the number *means*, using the
  same tone names as `statuses.js`), and `Page.jsx`'s `PageContainer`/
  `PageHeader`/`EmptyState`/`DetailItem`.
  `Components/UI/statuses.js` is the single source for every status →
  label + tone mapping in the staff app. A page must not invent its own
  colour for a status.
  `PageContainer` is the one content width and the one padding rule; a
  page that writes its own `max-w-*`/`px-*` is a bug waiting to be a
  responsive one.
- **The staff shell is a dark sidebar**, grouped Today / Records /
  Practice. It is the app's only dark surface and its only chrome. From
  `lg` up it collapses to a 64px icon rail, and the choice persists in
  `localStorage` under `staff.sidebar.collapsed`; below `lg` the same nav
  is an off-canvas drawer. One markup path serves all three states.
  Pages pass `title` and optional `breadcrumbs`/`actions`/`navBadges` to
  `AuthenticatedLayout`, not a `header` element. `breadcrumbs` is a
  `[{ label, href? }]` trail rendered after a Home crumb in the top bar;
  omit it and the trail is just `title`. A top-bar `actions` button is
  hidden from `lg` up, where the page's own `PageHeader` carries it.
- **Flash messages**: `HandleInertiaRequests` shares a fixed
  `flash.success` / `flash.error` shape that `Toast` renders. A
  controller action that changes something should say so with
  `->with('success', ...)`.
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
  (dispatched by `App\Jobs\SendAppointmentLookupLink`, which
  `AppointmentLookupController::send()` queues on every submission so a
  hit and a miss do identical work), and
  `AppointmentReminder.php` (sent from the
  `appointments:send-reminders` scheduled command — see Planning
  workflow), plus Laravel's built-in `VerifyEmail` notification, now
  genuinely sent on registration since `User` implements
  `MustVerifyEmail`, are the only senders so far. Anything else that
  would need to notify someone still surfaces in-app for staff to act
  on, unless it specifically needs to reach a guest with no account —
  then follow the same pattern.
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
  front-desk staff member. Register at `/register`, which requires a
  shared clinic registration code (`REGISTRATION_CODE` env var /
  `config('clinic.registration_code')`); a newly registered account must
  then verify its email (sent via the `log` mailer in dev) before it can
  reach any staff route.
  `db:seed --class=DemoSeeder` creates patients/providers/appointments
  plus exactly one throwaway staff `User` with random credentials (not
  intended as a working login — you still register at `/register`). That
  user only exists to own the `created_by` on the billing / treatment-plan
  / records fixtures, whose columns are NOT NULL.
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
- **Phase 7, sub-project 1** — invoicing & payments, specced at
  `docs/superpowers/specs/2026-08-29-invoicing-payments-design.md` — an
  `invoices` / `invoice_items` / `payments` trio, an `Admin\InvoiceController`
  (`index` / `show` / `store` / `update`, no `destroy`) and an
  `Admin\PaymentController` (`store` only — payments are append-only).
  An invoice starts as `draft` (line items, flat discount, and notes
  editable; each line optionally links to a `TreatmentPlanItem`, which
  pre-fills it and freezes a `provider_id` copy), is `issued` (freezes
  the lines), and can be `void`ed — `draft` freely, `issued` only while
  it has no payments. "Paid" is derived (`issued` + balance ≤ 0), never
  stored; so are `subtotal` / `total` / `amount_paid` / `balance`
  (computed from loaded relations via helper methods on `Invoice`).
  Payments are rejected above the current balance. Invoice numbers are
  derived display-only (`INV-` + padded id). Surfaces: a "Billing" tab
  on `/patients/{patient}`, `/invoices/{invoice}`, a `/invoices` index
  with status filters, and a dashboard outstanding-balances tile.
  Nothing is transmitted — no receipt slip. No refunds, deposits, tax,
  or revenue reporting yet.
- **Phase 7, sub-project 2** — reports & analytics, specced at
  `docs/superpowers/specs/2026-08-30-reports-analytics-design.md` — a
  read-only `Admin\ReportsController@index` (`GET /reports`, no model or
  migration) rendering `Reports/Index`: a date-range selector
  (`this_month` default / `last_month` / `this_quarter` / `ytd` /
  `last_12_months` / `custom` with a 400-day cap) resolved server-side to
  `[start, end]` UTC bounds, a time-series bucket granularity
  (day ≤ 31d, week ≤ 180d, else month) applied to every trend and
  gap-filled, and three sections of SQL aggregates — Revenue (collected
  vs invoiced vs outstanding-A/R, collected-over-time, by-provider and
  by-treatment on the invoiced basis, payment-method mix), Appointments
  (volume, status breakdown excluding `requested`, completion/
  cancellation/no-show rates, by-provider, by-type), Patients (new over
  time, returning vs first-visit, no-show patients). Charts render with
  a new lazy-chunked `recharts` dependency (area for trends, horizontal
  bars for breakdowns; stat tiles for headline numbers). Behind `auth`
  only — every staff member sees the whole report. Treatments-section
  analytics, recall-adherence, and any export (CSV/PDF) are deferred.
- **Phase 7, sub-project 3** — inventory, specced at
  `docs/superpowers/specs/2026-08-30-inventory-design.md` — a
  standalone staff-facing stock module with no dependency on billing,
  appointments, or clinical records. An `inventory_items` table
  (mutable: name, category, unit, `reorder_threshold`, supplier,
  item-level `expiry_date`, notes; `active` boolean for archive, no
  hard delete) plus an append-only `stock_movements` ledger
  (`received` / `consumed` / `adjustment` / `expired`, signed
  `quantity`, optional `unit_cost` on receipts). An item's on-hand is
  the derived `SUM(quantity)` — never stored — and a movement that
  would drive it negative is rejected under a row lock (the
  `PaymentController` pattern). `Admin\InventoryItemController`
  (`index` / `show` / `store` / `update`, no `destroy`) and
  `Admin\StockMovementController` (`store` only). Surfaces: a
  `/inventory` index (filters All / Low stock / Expiring / Archived +
  name search), `/inventory/{item}` with the movement history, and a
  dashboard low/expiring tile. Nothing is transmitted — low-stock and
  expiry are in-app only. Batch/lot tracking, valuation reporting, a
  purchase-order workflow, and consumption↔appointment linkage are
  deferred.
- **Phase 8, sub-projects 2 and 3** — data-integrity and deployment
  hardening, specced at
  `docs/superpowers/specs/2026-08-30-data-integrity-hardening-design.md`
  and `...-deployment-hardening-design.md`. Patients with any history
  cannot be deleted; invoice status and transition legality are decided
  under the row lock; board statuses require a schedulable appointment
  (`Appointment::BOARD_STATUSES`); profile deletion guards all eight
  `created_by` owners and deletes before tearing down the session;
  `paid_on`/`occurred_on` are bounded at today; stock cannot be *added*
  to an archived item; booking fields are `Rule::in` against
  `config('clinic.bookable_services'/'bookable_dentists')`; `POST /book`
  is 3/hour with a 180-day horizon; the appointment lookup does equal
  work on hit and miss via `App\Jobs\SendAppointmentLookupLink`;
  `patients.email` is unique. Plus `App\Http\Middleware\SecurityHeaders`
  (CSP with a per-request nonce, HSTS on secure requests, nosniff,
  `frame-ancestors 'none'`, Referrer-Policy, Permissions-Policy),
  trusted proxies/hosts, forced HTTPS in production, a scoped Ziggy
  route group for guests, and `.env.production.example`.
- **Phase 9** — the staff app's design system and shell, specced at
  `docs/superpowers/specs/2026-08-31-staff-app-design-system.md`. See
  Layout conventions above for what it established.
- **Phase 10** — closing the recorded known gaps, specced at
  `docs/superpowers/specs/2026-08-31-known-gap-closure-design.md`.
  `patients.index` / `invoices.index` / `inventory.index` and
  `Patient::dueForRecall()` do their work in the database and paginate;
  `Appointment::TRANSITIONS` constrains status moves;
  `POST /book` does identical work for a known and an unknown email;
  `paid_on` / `occurred_on` have a floor; an append-only `audit_log` plus
  `/activity` records and shows the thirteen actions worth asking about
  afterwards; and `DesignSystemTest` turns the Layout conventions below
  into lint rules.

## Known gaps

- `Components/UI/statuses.js` mirrors six server-side status constants by
  hand. A missing key degrades to a humanised label rather than
  crashing, but the two can still drift.
- Invoice money now exists twice: as PHP over loaded relations
  (`balance()` etc., right for one invoice) and as SQL
  (`Invoice::balanceSql()` and the `outstanding()` / `settled()` scopes,
  right for a list). `ListQueryTest` asserts they agree; a change to one
  must change the other. The same applies to `InventoryItem::onHand()`
  and `onHandSql()`.
- `Appointment::TRANSITIONS` is permissive about corrections on purpose —
  every mis-click has a one-step way back — so it constrains nonsense,
  not workflow. It is not a clinical policy engine, and a clinic wanting
  "a completed visit is final" has to say so.
- The audit log has no retention policy and grows forever, and every
  staff member can read `/activity` because the app has no roles. A
  clinic wanting the log restricted to a practice manager needs the roles
  work that is deferred across the whole product.
- `DesignSystemTest` is a set of regexes over source files. It catches the
  drift that actually happened; it is not a substitute for looking at the
  page.
- Waiting time on `/queue` is measured from the scheduled start, not from
  check-in: there is no `checked_in_at` column. It reads as "how late are
  we running against what this patient was told", which is the more
  useful number, but it is not literally time-in-the-waiting-room.
- The public booking form's service/dentist lists now come from
  `config/clinic.php`, but `resources/js/Data/services.js` and
  `dentists.js` still hold the richer marketing copy for the same
  things. Related data, not the same data — keep the names in step.
- Guest bookings still merge into an existing patient record on an email
  match, so anyone who knows a patient's email can append a `requested`
  appointment to their history for staff to triage. Deliberately left
  open in Phase 10: not merging needs `appointments.patient_id` to become
  nullable, a reconciliation queue, and a decision about what every
  *genuine* returning patient's booking then costs the front desk — a
  change to a shipped Phase 3 workflow and to the schema, not a cleanup.
  Now that Phase 8 constrained the fields, the residual risk is a bogus
  `requested` row carrying a canonical service name: nuisance triage, not
  data corruption.
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
- Invoice numbers are derived from the primary key (`INV-` + padded
  id) — not gapless, and they shift if rows are ever hard-deleted. A
  real clinic needing statutory numbering would want a dedicated
  counter.
- Every `/reports` query is unbounded and unpaginated; by-provider /
  by-treatment load all matching `invoice_items` for the range, and
  outstanding-A/R re-derives `balance()` by loading every issued invoice
  with its items and payments (same accepted O(n) pattern as the
  dashboard tile). Fine at demo scale; a multi-year dataset would want
  summary tables or date-partitioned indexes on `payments.paid_on`,
  `invoices.issued_at`, `appointments.start_time`, `patients.created_at`.
- Reports "invoiced revenue by provider" is gross of invoice-level
  discount (a discount is not allocable to one line/provider); the
  invoiced *total* is net. Both are labelled in the UI.
- Reports date ranges are UTC boundaries — no timezone handling, so a
  non-UTC clinic sees report days roll over off-midnight local. Matches
  the rest of the app.
- Inventory on-hand is re-derived (`SUM(stock_movements.quantity)`) on
  every read — the `/inventory` index, the item page, and the
  dashboard tile — with no cached column. Same accepted O(n) pattern
  as invoice balances and `Patient::dueForRecall()`.
- Inventory expiry is a single item-level `expiry_date`, not
  per-batch/lot — a clinic holding multiple lots of one item with
  different expiries can't represent that. FEFO batch tracking is a
  future slice.
- Stock quantities are integers — no fractional units. The free-text
  `unit` may read "ml" but movements are whole numbers.
- `InventoryItem::CATEGORIES`, `StockMovement::TYPES`, and the
  frontend-only common-units `<datalist>` list are duplicated in the
  React `<select>`s — the same docblock-sync situation as the
  appointment / treatment / invoice status sets.
- `stock_movements.unit_cost` is captured on `received` movements but
  nothing aggregates it — no inventory valuation or purchase-spend
  reporting yet.
- Inventory movement overdraw protection is a check-then-act guarded by
  a `SELECT ... FOR UPDATE` on the item row (the `PaymentController`
  pattern) — correct for a single node, and the lock makes concurrent
  movements on one item safe.
- The registration code is a single shared secret with no rotation
  mechanism, no per-user attribution, and no expiry. If it leaks, it is
  changed by editing the environment and restarting. Adequate for a
  flat-staff model; a real clinic wanting to know who admitted whom
  needs per-invite tokens.
- `Password::defaults()` omits `->uncompromised()` to avoid an outbound
  network call, so a password that is long and mixed but publicly
  breached is still accepted.
- `AuthenticateSession` logs out sibling sessions on a password change
  but there is still no "sign out all devices" control, and no list of
  active sessions.
- Registration remains self-service. A clinic that wants staff accounts
  provisioned centrally has no admin-side "create user" screen.
