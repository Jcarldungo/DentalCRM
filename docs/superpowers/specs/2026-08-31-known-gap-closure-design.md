# DentalCRM — Phase 10: Closing the recorded known gaps — Design

Status: implemented, 2026-08-31.

## Purpose

Phases 8 and 9 each shipped with a list of things deliberately left
undone, recorded in CLAUDE.md's "Known gaps". This phase closes the ones
that are engineering rather than product decisions, and says plainly
which one is not.

The unifying theme is that each of these was a *documented* weakness —
which is better than an undocumented one, and worse than a fixed one.

## Findings addressed

| # | Gap | Kind |
|---|-----|------|
| G1 | `Patient::dueForRecall()` reads every patient and every cleaning on every dashboard load | Performance |
| G2 | `/invoices` loads every invoice with items and payments, derives money in PHP, filters the collection | Performance |
| G3 | `/inventory` reads every item's whole movement ledger, filters in PHP | Performance |
| G4 | Appointment status transitions unconstrained beyond `Rule::in` | Data integrity |
| G5 | `POST /book` is a patient-existence timing oracle | Privacy |
| G6 | No lower bound on `paid_on` / `occurred_on` | Data integrity |
| G7 | No audit log — several Phase 8 findings were preventable but not detectable | Observability |
| G8 | The design system is enforced by review alone | Maintainability |
| G9 | Guest bookings merge into an existing patient on an email match | **Not fixed — see below** |

## Design

### 1. The lists do their work in the database (G1, G2, G3)

Each of these had the same shape: read everything, compute in PHP,
discard most of it. Each is now a bounded query.

`Patient::dueForRecall()` was the worst — it ran on *every* dashboard
load. It becomes one query joining each patient to their most recent
completed cleaning, with the interval comparison in the `WHERE` clause.

`Invoice` gains its four money figures as SQL (`subtotalSql()`,
`totalSql()`, `amountPaidSql()`, `balanceSql()`) plus `outstanding()` and
`settled()` scopes; `InventoryItem` gains `onHandSql()` plus `lowStock()`
and `expiringSoon()`. Both lists paginate, and the four other places that
derived the same figures per row — the two dashboard tiles, the patient
list's balances, and the reports A/R number — use the same scopes.

**The money now exists twice**: as PHP over loaded relations, which is
right for one invoice, and as SQL, which is right for a list. They must
agree. `ListQueryTest` asserts that across six shapes, including
fractional amounts and an invoice with no items, and asserts that the
dashboard and reports report the same number.

The invoice list gained search by patient name or invoice number at the
same time, because a paginated list with no search is worse than an
unpaginated one.

### 2. Appointment transitions are a table (G4)

`Appointment::TRANSITIONS` is **permissive about corrections and strict
about nonsense**. Every step a front-desk member can take by mis-clicking
has a way back, one step at a time — checked in by accident returns to
scheduled, started treatment returns to checked in, completed too early
returns to in treatment. A stricter machine would leave staff with a
wrong record and no way to fix it, which is worse than the problem it
solves.

What it forbids:

- Nothing may become `requested`. A request is what a guest creates on
  the public site; a status change must not fabricate one.
- `completed` steps back only to `in_treatment`, never straight to
  `scheduled`, which would erase that the visit happened.
- `declined` may only be reconsidered into `scheduled`.

The calendar's status picker receives the same table, so a receptionist
is never offered an option that returns an error.

### 3. The booking endpoint does the same work either way (G5)

The oracle was structural: `SELECT`, and `INSERT` only on a miss.
`insertOrIgnore` makes both paths one insert and one select, with the
unique index on `patients.email` turning the insert into a no-op for a
known address. An existing patient's details are still never overwritten,
and the check-then-act disappears with it.

Asserted on the statements issued, which is deterministic, rather than on
wall-clock timing, which is not.

### 4. Money dates have a floor the data already carries (G6)

A payment cannot predate its invoice being issued; a stock movement
cannot predate the item it moves. Both are rules the records already
imply, rather than arbitrary ones, and both turn a typo'd year — which
previously reduced a balance while placing the money outside every
reports range — into an error.

### 5. An audit log, and a page to read it (G7)

`audit_log` is append-only, like the ledgers it watches. It is
deliberately **not** a model observer over every save:

- A blind dump of changed attributes would copy patient names, emails,
  and clinical free text into a second table nothing else guards. Every
  call site passes the context it wants recorded, and a test asserts a
  payment entry carries its amount and method and not the operator's note.
- An observer logs everything equally, which makes a log unreadable.

Thirteen actions are recorded: the ones a clinic would actually go
looking for — money moving, records destroyed *or refused*, and clinical
state changing. `/activity` reads it, because a log nobody can read is
not a log.

The log outlives the accounts it describes: `user_id` nulls out when a
staff account goes, but the entry and the recorded actor name do not.

### 6. The design system is enforced by the suite (G8)

`DesignSystemTest` turns CLAUDE.md's Layout conventions into lint rules
over every staff page: no hand-rolled dialogs, no `window.confirm`, no
page-level `max-w-*`, no local status colour map, no `<label>` that names
nothing, no `gray` where the app uses `slate`, and no leaking of the
public site's stone/teal palette.

Each failure names the file and says what to use instead. Verified by
planting one of each violation and watching the rules fail.

## Not fixed: guest bookings still merge on an email match (G9)

Anyone who knows a patient's email can still attach a `requested`
appointment to that patient's record for staff to triage.

The stronger design — create an unlinked request for staff to reconcile —
needs `appointments.patient_id` to become nullable, a reconciliation
queue, and a decision about what every *genuine* returning patient's
booking now costs the front desk. That is a change to a shipped Phase 3
workflow and to the database's shape, not an engineering cleanup, and
building it without that decision would make the common case worse to
improve the rare one.

What the risk actually is, now that Phase 8 constrained the fields: a
bogus `requested` row attributed to a real patient, carrying a canonical
service name and a date. It is nuisance triage, not data corruption — the
patient's name, phone, and date of birth are never overwritten, and
`notes` is not rendered on the lookup page.

## Testing

- `ListQueryTest` — SQL and PHP money agree across six shapes; the
  outstanding scope matches the PHP definition; dashboard and reports
  agree; both lists paginate; the invoice summary covers every page, not
  just the current one; `dueForRecall()` is one query regardless of
  patient count; the dashboard's query count does not grow with data.
- `AppointmentTransitionTest` — all 63 cases: every declared move
  accepted, every undeclared one refused, staying put always allowed,
  plus the four queue-board buttons.
- `AuditLogTest` — each recorded action, that a routine edit records
  nothing, that an entry survives its actor's deletion, and that context
  carries no free text.
- `DesignSystemTest` — the lint rules, verified against planted
  violations.
- `BookingTest` — a known and an unknown email issue the same statements.
- `PaymentTest` / `StockMovementTest` — the new floors.

## Known gaps after this phase

- G9 above.
- `/reports` queries remain unbounded and unpaginated over their range.
- `Appointment::countBookedForSlot()` and `hasConflict()` remain
  check-then-act and unindexed.
- No roles: every staff member can read `/activity`.
- The audit log has no retention policy; it grows forever.
