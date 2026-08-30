# DentalCRM — Phase 9: Staff app design system & UX overhaul — Design

Status: draft, 2026-08-31.

## Purpose

The public site was designed. The staff app was not — it is still the
Laravel Breeze scaffold with pages bolted on, and that shows in ways that
are now measurable rather than a matter of taste:

- **Every staff page overflows horizontally at 768px.** The top nav holds
  ten items at `space-x-8` inside `max-w-7xl`, forcing the document to
  1207px wide in a 768px viewport. A dentist on a chairside tablet — the
  single most likely non-desktop device for this product — gets a page
  they must pan sideways to read.
- **Every staff page has zero horizontal padding below 640px**
  (`py-8 max-w-4xl mx-auto sm:px-6 lg:px-8` — no base `px-4`), so cards
  run edge to edge on a phone.
- **The Laravel logo is still the product's mark.**
- **Five hand-rolled modals** (`fixed inset-0 bg-black/40`) with no focus
  trap, no Escape handling, no scroll lock, no `role="dialog"`, and no
  focus restoration — while a correct Headless UI `Modal` component sits
  unused in `resources/js/Components/`, wired only into the Breeze
  profile pages.
- **No `<label for>` anywhere in the staff app.** Roughly sixty inputs
  use `<label className="block text-sm mb-1">` followed by a sibling
  `<input>`, so no input has an accessible name.
- **Six page containers at four different widths** (`max-w-3xl`,
  `max-w-4xl`, `max-w-5xl`, `max-w-7xl`) inside a `max-w-7xl` nav. The
  dashboard is `max-w-3xl` — on a 1440px screen roughly 70% of the
  viewport is empty grey.
- **Status colour is invented per page.** Appointment, treatment-plan,
  invoice, prescription, inventory, and tooth-condition statuses each
  carry their own hand-written Tailwind class strings, in six files.
- **Destructive actions look like ordinary ones.** "Delete" is a
  `text-sm text-red-600` link sitting beside "Edit", confirmed with
  `window.confirm()`.
- **No success feedback exists.** Nothing shares or renders a flash
  message, so every save is silent.

## Constraints

- **The public site keeps its identity.** Teal/stone stays, and the two
  worlds stay separate per CLAUDE.md. The public site is refined here
  (its own 768px overflow), not redesigned.
- **No new runtime dependencies.** `lucide-react` and `@headlessui/react`
  are already installed and are enough.
- **No behavioural change to business rules.** This phase changes what
  the app looks like and how it is operated, not what it permits. Where a
  screen gains information (a patient's balance on their header, say) it
  comes from data the controller already loads or a query of the same
  shape.
- **No over-abstraction.** Components are extracted where the same thing
  appears three or more times. Pages stay readable as pages.

## Design

### 1. A visual identity for the staff app

Two palettes, deliberately unrelated:

| | Public site | Staff app |
|---|---|---|
| Neutral | `stone` (warm) | `slate` (cool) |
| Primary | `teal-700` | `brand-600` |

`brand` is a custom desaturated navy-blue scale in `tailwind.config.js`.
It reads as clinical software rather than marketing, and at `brand-600`
(#2A54A0) it clears 7:1 against white for both text and white-on-button.

The Laravel mark is replaced by a wordmark built from
`config('clinic.name')`, so the product's own identity is data-driven
like the rest of clinic identity.

### 2. Application shell: a sidebar

Ten destinations do not fit a horizontal bar, and hiding six of them
behind a "More" menu adds a click to half the app. The shell becomes:

- **`lg` and up:** a fixed 260px sidebar, grouped into **Today**
  (Dashboard, Queue, Workspace), **Records** (Patients, Appointments,
  Inquiries), and **Practice** (Billing, Reports, Inventory, Providers),
  each item an icon plus a label.
- **Below `lg`:** the sidebar becomes an off-canvas drawer behind a
  hamburger, using the same component and the same markup.
- A slim top bar carries the page title, page-level actions, and the
  account menu.

This is what removes the 768px overflow at the root rather than per page.

### 3. Layout primitives

- One container: `max-w-[88rem]` with `px-4 sm:px-6 lg:px-8`. The base
  `px-4` is the mobile-padding fix; the wider max lets dense pages
  (queue, reports, invoices) use the screen.
- One card: `rounded-xl border border-slate-200 bg-white`, hairlines
  rather than drop shadows — a border-led surface stays legible at the
  densities clinical data needs, where stacked shadows turn to mud.
- One spacing rhythm: 4/8/12/16/24 between related things, 32 between
  sections.
- Type: page title `text-xl font-semibold`, card title
  `text-sm font-semibold`, body `text-sm`, metadata
  `text-xs text-slate-500`. Money and counts get `tabular-nums`.

### 4. Shared components

New under `resources/js/Components/UI/`, each replacing three or more
existing hand-rolled copies:

| Component | Replaces |
|---|---|
| `Button` | ~40 inline `rounded bg-gray-900 px-4 py-2 text-white` strings, and the Breeze Primary/Secondary/Danger trio |
| `Card` | ~30 `bg-white shadow rounded p-4` divs |
| `Modal` | 5 hand-rolled overlay divs — Headless UI `Dialog`, so focus trap, Escape, scroll lock, and focus restoration come for free |
| `ConfirmDialog` | 2 `window.confirm()` calls, which cannot describe consequences |
| `Field` / `SelectField` / `TextareaField` | ~60 unlabelled inputs; each generates an `id`, wires `htmlFor`, and links errors with `aria-describedby` and `aria-invalid` |
| `StatusBadge` + `statuses.js` | 6 per-page colour maps |
| `PageHeader` | 12 header blocks |
| `EmptyState` | 10 bare "No X yet." strings |
| `Toast` | nothing — success feedback does not exist today |

`statuses.js` is the single place any status maps to a label and a tone,
for all six status domains.

### 5. Page-level work

Only where the current design fails a real task:

- **Dashboard.** Today is invisible: the page shows recall, outstanding
  balances, and inventory, and nothing about the day being worked.
  It becomes: a today strip (scheduled / waiting / in treatment /
  completed, each linking into the queue), pending appointment requests
  (the item with an SLA — a guest is waiting on it), then outstanding
  balances, recall, and inventory. Four tiles across at `xl`, two at
  `sm`, one on a phone.
- **Patients.** Add a search box (server-side, on name/phone/email) and
  pagination — the index loads every patient today. Each row gains last
  visit, next appointment, and outstanding balance, so the list answers
  the receptionist's actual questions instead of just naming people.
- **Patient detail.** A real header — name, age, DOB, phone, email,
  balance, next appointment, alert flags — then the tabs. The tab bar
  becomes a proper `role="tablist"` with arrow-key navigation and
  horizontal scroll on narrow screens instead of overflowing the page.
- **Dental chart.** The current chart is two flat rows of sixteen 36px
  squares. It becomes an anatomically arranged arch: upper and lower,
  split at the midline into quadrants, with `R` / `L` markers, per-tooth
  `aria-label`s carrying the condition (colour is not the only channel),
  a visible selected state, and a legend that only lists conditions the
  patient actually has. It scrolls inside its own container on a phone
  rather than widening the document.
- **Queue.** Four equal columns become a board where **Now Serving** is
  visually dominant, cards show waiting time, and the columns become a
  vertical stack on a phone.
- **Appointments.** Calendar events get their status colour, so a
  cancelled slot stops looking booked; the business-hours window is
  clamped to clinic hours instead of showing 6am–midnight; declining a
  request asks for confirmation, because it emails the patient.
- **Invoices.** Balance due becomes the headline; status becomes a badge.

### 6. Accessibility

- Every input gets a programmatic name via the `Field` components.
- Every modal is a Headless UI `Dialog`.
- Tabs get `role="tablist"` / `role="tab"` / `aria-selected` and
  arrow-key navigation.
- A visible focus ring (`focus-visible:ring-2 ring-brand-500
  ring-offset-2`) on every interactive element, applied through the
  shared components rather than per call site.
- A "Skip to content" link, and `<main id="main">`.
- Status is never conveyed by colour alone — every badge and every tooth
  carries text or an accessible name.
- Interactive targets reach 40px minimum; teeth are 40px.
- Motion is limited to 120–200ms opacity/transform, and everything is
  disabled under `prefers-reduced-motion`.

### 7. Flash messages

`HandleInertiaRequests` shares a fixed `flash.success` / `flash.error`
shape; the shell renders a dismissible, `role="status"` toast. Controllers
that already `return back()` gain a message where a silent save is
currently confusing.

## Testing

The design work is mostly not unit-testable, so verification is:

- The existing suite is the regression net for every controller change
  (patient search and pagination, dashboard props, flash messages).
- New tests for the behavioural additions only: patient search and
  pagination, the dashboard's today counts, and the flash-message shape.
- A scripted browser pass over every page at 1440 / 1280 / 1024 / 768 /
  390, asserting `document.scrollWidth <= clientWidth` and capturing
  console errors — this is what found the 768px overflow and would catch
  its return.
- `npm run build` must pass.

## Out of scope

- Any change to the public site beyond fixing its 768px overflow and
  reusing the shared booking-list props.
- Roles and permissions. Every user is still an equal staff member, so
  "Administrator" remains a person, not a role.
- Server-side search or pagination anywhere except `patients.index`;
  `/invoices` and `/inventory` keep their documented O(n) behaviour.
- A print stylesheet for invoices or prescriptions.
- Dark mode.
