# DentalCRM — Phase 7 sub-project 3: Inventory — Design

Status: approved by user, 2026-08-30.

## Purpose

Phase 7 of `docs/PLATFORM_VISION.md` (§17: Inventory) is the last piece
of "Business Operations", after invoicing (sub-project 1) and reports
(sub-project 2). It is a **standalone staff-facing stock module** — no
dependency on billing, appointments, or clinical records. A front-desk
staff member can register the consumables and equipment the clinic
keeps, record every movement of stock in and out, see at a glance what
is running low or nearing expiry, and never have to reconcile a running
count by hand.

Staff can:

- Open `/inventory` and see every active item with its current on-hand
  quantity, its reorder threshold, a stock-status badge (OK / Low /
  Out), and an expiry flag — filterable to All / Low stock / Expiring /
  Archived, searchable by name
- Add a new item: name, category, unit, reorder threshold, optional
  supplier, optional expiry date, optional notes, and an optional
  opening quantity
- Edit any of those item fields at any time (items are mutable, unlike
  the append-only clinical records)
- Archive an item that is no longer stocked, and restore it later —
  archived items drop out of the default list and the dashboard counts
  but keep their full history
- Open an item's page and record a **stock movement**: `received`
  (a delivery, with an optional unit cost), `consumed` (used in the
  clinic), `expired` (written off past its date), or `adjustment` (a
  physical stock-count correction, up or down, with a required reason)
- See the item's complete movement history — every row immutable, with
  who recorded it and when
- See a dashboard "Inventory" tile — how many items are low/out and how
  many are expiring soon, linking to the filtered index

The current on-hand quantity of an item is a **derived figure** — the
signed sum of its `stock_movements` — never a stored column. This
mirrors how invoicing derives an invoice balance from its append-only
payments, and directly answers §17's instruction to "avoid treating
inventory as simple CRUD if stock movement history is required".

## Constraints

- No RBAC. Every authenticated user can create, edit, archive, and
  record movements against any item — same as every other staff feature
  in the app today.
- **Nothing is transmitted anywhere.** Low-stock and expiry warnings
  surface only in-app — the `/inventory` index and a dashboard tile.
  No email, no SMS (per CLAUDE.md Hard constraints — the "staff
  notification: low inventory" in vision §19 is an in-app surface here,
  same stance the rest of the app takes).
- **Stock movements are strictly append-only** — no update route, no
  destroy route, no controller method for either, no UI control to
  reach one. A mistaken movement is corrected by recording a
  compensating `adjustment`. Called out again in "Out of scope".
  This is the same posture `PaymentController` takes.
- **On-hand can never go negative.** A `consumed` / `expired` /
  decreasing `adjustment` that would drive an item's on-hand below zero
  is rejected with a `ValidationException` naming the quantity actually
  available. Enforced under a row lock, same as overpayment rejection
  in `PaymentController`.
- An **item is mutable**: `name`, `category`, `unit`,
  `reorder_threshold`, `supplier`, `expiry_date`, `notes`, and `active`
  are all editable via `PATCH /inventory/{item}` for the life of the
  item. There is no item `destroy` — retiring an item means setting
  `active = false` (the `Provider::active` pattern, not soft deletes).
- **Quantities are integers.** Dental stock is counted in whole units
  (boxes, pieces, pairs, cartridges). `unit` is free text and may read
  "ml", but movements are whole numbers. Fractional units → Known gaps.
- **Expiry is a single item-level date**, staff-maintained when a new
  batch is received. No per-batch / per-lot tracking, no FEFO
  drawdown. A clinic holding two lots of one item with different
  expiries cannot represent that — Known gaps, and a plausible future
  slice.
- `unit_cost` is **captured on `received` movements only** and is
  informational — it appears in the movement history and nowhere else.
  No valuation, no purchase-spend reporting in this slice. For any
  other movement type a supplied `unit_cost` is ignored (stored `null`).
- `StockMovement::TYPES` and `InventoryItem::CATEGORIES` follow the
  existing `Payment::METHODS` / `DentalRecord::TYPES` pattern — used by
  both server validation and the frontend `<select>`s.
- Money columns are `decimal(10,2)`, pesos (`₱`) — matching `invoices`.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump()`, no unused
  imports, no commented-out code.
- Commits carry NO `Co-Authored-By` trailer (matches repo history).
  Short imperative subjects. One commit per plan task.

## Architecture

Same Laravel 12 + Inertia 2 + React 18 + Tailwind 3 app, no new
packages. Two migrations, two models, two factories, two controllers,
five routes, two new Inertia pages, and additions to
`DashboardController`, `Dashboard.jsx`, `AuthenticatedLayout.jsx`,
`DemoSeeder`, and `CLAUDE.md`.

```
routes/web.php (auth group, after the /invoices routes)
  GET    /inventory                          Admin\InventoryItemController@index    name: inventory.index
  POST   /inventory                          Admin\InventoryItemController@store    name: inventory.store
  GET    /inventory/{inventoryItem}          Admin\InventoryItemController@show     name: inventory.show
  PATCH  /inventory/{inventoryItem}          Admin\InventoryItemController@update   name: inventory.update
  POST   /inventory/{inventoryItem}/movements Admin\StockMovementController@store   name: inventory-movements.store
```

```
database/migrations/..._create_inventory_items_table.php    new
database/migrations/..._create_stock_movements_table.php    new
database/factories/InventoryItemFactory.php                 new
database/factories/StockMovementFactory.php                 new
app/Models/InventoryItem.php                                new
app/Models/StockMovement.php                                new
app/Http/Controllers/Admin/InventoryItemController.php      new
app/Http/Controllers/Admin/StockMovementController.php      new
app/Http/Controllers/Admin/DashboardController.php          + inventory tile data
database/seeders/DemoSeeder.php                             + inventory fixtures

resources/js/Pages/Inventory/Index.jsx                      new
resources/js/Pages/Inventory/Show.jsx                       new
resources/js/Pages/Dashboard.jsx                            + Inventory tile
resources/js/Layouts/AuthenticatedLayout.jsx               + Inventory nav link (desktop + responsive)

CLAUDE.md                                                   + shipped-so-far bullet + Known gaps notes
```

No new patient-tab component, no change to `PatientController` — this
module does not attach to the patient page.

## Data model

### `inventory_items` table

| Column | Type | Notes |
|---|---|---|
| `name` | `string` | required, max 255 |
| `category` | `string` | one of `InventoryItem::CATEGORIES` |
| `unit` | `string` | required, max 20 — free text ("box", "piece", "pair", "cartridge", "ml") |
| `reorder_threshold` | `unsignedInteger` | default `0`; on-hand `<=` this ⇒ "Low" |
| `supplier` | `string` nullable | max 255, free text — no supplier directory |
| `expiry_date` | `date` nullable | single item-level date |
| `notes` | `text` nullable | |
| `active` | `boolean` | default `true`; `false` = archived |
| `created_by` | `foreignId` → `users` | required, `constrained('users')`, set server-side only, never mass-assigned |
| `created_at`/`updated_at` | `timestamp` | standard timestamps |

### `stock_movements` table

| Column | Type | Notes |
|---|---|---|
| `inventory_item_id` | `foreignId` | required, `constrained()->cascadeOnDelete()` |
| `type` | `string` | one of `StockMovement::TYPES` |
| `quantity` | `integer` | **signed** — `> 0` for `received` and increasing `adjustment`; `< 0` for `consumed`, `expired`, decreasing `adjustment`. Never `0`. |
| `unit_cost` | `decimal(10,2)` nullable | `₱`; only stored for `received`, else `null` |
| `reason` | `string` nullable | max 255; **required** for `adjustment`, optional otherwise (a supplier/PO note on `received`, a "why" on `consumed`/`expired`) |
| `occurred_on` | `date` | required; defaults to today when omitted |
| `created_by` | `foreignId` → `users` | required, `constrained('users')`, set server-side only, never mass-assigned |
| `created_at`/`updated_at` | `timestamp` | standard timestamps |

### Consts

```php
InventoryItem::CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
StockMovement::TYPES      = ['received', 'consumed', 'adjustment', 'expired'];
```

The frontend also carries a **common-units** suggestion list
(`box`, `piece`, `pair`, `pack`, `cartridge`, `bottle`, `tube`, `roll`,
`ml`) for a `<datalist>` — suggestions only, `unit` is stored as typed.
This list is frontend-only (no server const); noted in Known gaps
alongside the two consts.

### Casts & relations

- `InventoryItem`: `expiry_date` → `date:Y-m-d`, `active` → `boolean`,
  `reorder_threshold` → `integer`. `movements(): HasMany` (ordered by
  `occurred_on` then `id`), `creator(): BelongsTo(User::class,
  'created_by')`.
- `StockMovement`: `quantity` → `integer`, `unit_cost` → `decimal:2`,
  `occurred_on` → `date:Y-m-d`. `item(): BelongsTo(InventoryItem::class)`,
  `creator(): BelongsTo(User::class, 'created_by')`.

`created_by` is excluded from both models' `$fillable`; each controller
sets it from `$request->user()->id` after validation — identical to
`Invoice` / `Payment` / `Prescription`.

### Derived figures (never stored)

For a given item, from its loaded (or `withSum`-aggregated) movements:

```
on_hand      = sum(stock_movements.quantity)        (integer; >= 0 always, enforced on write)
is_low       = on_hand <= reorder_threshold
is_out       = on_hand <= 0
is_expiring_soon = expiry_date !== null && expiry_date <= today + 30 days   (past dates included)
stock_status = is_out ? 'out' : (is_low ? 'low' : 'ok')
```

`withSum('movements as on_hand', 'quantity')` returns `null` for an item
with no movements — coerce with `(int)`.

The controllers compute these with a DB aggregate (`withSum`) on list
views and from the loaded relation on the item page. No trigger, no
cached column, no observer — the same accepted O(n) pattern as invoice
balances (Known gaps).

## Model behaviour

`InventoryItem`:

```php
public function onHand(): int          // (int) $this->movements->sum('quantity')  — expects movements loaded
public function isLow(): bool           // $this->onHand() <= $this->reorder_threshold
public function isExpiringSoon(int $days = 30): bool
public function stockStatus(): string   // 'out' | 'low' | 'ok'
```

`isExpiringSoon` uses the injected clock via `now()` so it is testable
with `Carbon::setTestNow()` (the Reports slice established this
expectation — see its final-review fixes).

`StockMovement` has no derived methods — it is a plain immutable record.

## `InventoryItemController`

### `index(Request $request): Response`

`GET /inventory`. Validates:

- `filter` → `nullable`, `Rule::in(['all', 'low', 'expiring', 'archived'])` (default `all`)
- `search` → `nullable`, `string`, `max:255`

Loads every item `withSum('movements as on_hand', 'quantity')`, ordered
by `name`. Filters **server-side**:

- `all` — `active === true`
- `low` — `active === true` && `on_hand <= reorder_threshold`
- `expiring` — `active === true` && `expiry_date !== null` && `expiry_date <= today+30d`
- `archived` — `active === false`

Then applies `search` (case-insensitive `name LIKE %term%`) across
whichever set the filter produced.

No pagination — consistent with `patients.index` / `invoices.index`
(Known gaps). Renders `Inventory/Index` with:

- `items` — `[{ id, name, category, unit, on_hand (int), reorder_threshold,
  stock_status ('ok'|'low'|'out'), supplier, expiry_date (Y-m-d|null),
  is_expiring_soon (bool), active (bool) }]`
- `filters` — `{ filter, search }`, echoed from the validated query string

### `show(InventoryItem $inventoryItem): Response`

`GET /inventory/{inventoryItem}`. Loads `movements.creator` and
`creator`. Renders `Inventory/Show` with one `item` prop:

```php
[
  'id' => ...,
  'name' => ..., 'category' => ..., 'unit' => ...,
  'reorder_threshold' => ..., 'supplier' => ..., 'notes' => ...,
  'expiry_date' => $item->expiry_date?->toDateString(),
  'is_expiring_soon' => $item->isExpiringSoon(),
  'active' => $item->active,
  'on_hand' => $item->onHand(),
  'stock_status' => $item->stockStatus(),
  'created_at' => $item->created_at->toIso8601String(),
  'creator_name' => $item->creator->name,
  'movements' => [ { id, type, quantity (signed int), unit_cost (float|null),
                     reason, occurred_on (Y-m-d), created_at (ISO), creator_name } ],
]
```

The `movements` array is projected **newest-first** — the relation is
ordered oldest-first (`occurred_on`, `id`), so `present()` reverses the
loaded collection before mapping. The page renders the array as given.

### `store(Request $request): RedirectResponse`

`POST /inventory`:

1. Validates:
   - `name` — `required`, `string`, `max:255`
   - `category` — `required`, `Rule::in(InventoryItem::CATEGORIES)`
   - `unit` — `required`, `string`, `max:20`
   - `reorder_threshold` — `nullable`, `integer`, `min:0`, `max:1000000`
   - `supplier` — `nullable`, `string`, `max:255`
   - `expiry_date` — `nullable`, `date`
   - `notes` — `nullable`, `string`
   - `opening_quantity` — `nullable`, `integer`, `min:0`, `max:1000000`
2. Creates the item: validated fields (`reorder_threshold` defaulting
   to `0`), `active = true`, `created_by = $request->user()->id`
   (direct assignment, not mass-assignment).
3. If `opening_quantity` is present and `> 0`, creates one
   `stock_movements` row inside the same transaction: `type =
   'adjustment'`, `quantity = opening_quantity` (positive), `reason =
   'Opening balance'`, `occurred_on = today`, `unit_cost = null`,
   `created_by` = the same user. (An opening balance is a count
   assertion, not a purchase — hence `adjustment`, not `received`.)
4. `return redirect()->route('inventory.show', $inventoryItem)`.

### `update(Request $request, InventoryItem $inventoryItem): RedirectResponse`

`PATCH /inventory/{inventoryItem}`. Single mode — there is no state
machine (an item is not a document with a lifecycle). Validates with
`sometimes` so a partial payload works (the archive button sends only
`{ active: false }`):

- `name` — `sometimes`, `required`, `string`, `max:255`
- `category` — `sometimes`, `required`, `Rule::in(InventoryItem::CATEGORIES)`
- `unit` — `sometimes`, `required`, `string`, `max:20`
- `reorder_threshold` — `sometimes`, `nullable`, `integer`, `min:0`, `max:1000000`
- `supplier` — `sometimes`, `nullable`, `string`, `max:255`
- `expiry_date` — `sometimes`, `nullable`, `date`
- `notes` — `sometimes`, `nullable`, `string`
- `active` — `sometimes`, `boolean`

Applies the validated subset (`reorder_threshold` coerced to `0` when
explicitly `null`). `created_by` / `id` are never touched. `return
back()`.

### No `destroy`

No `destroy` method, no `inventory.destroy` route, no UI affordance. A
test asserts the route name does not exist.

## `StockMovementController`

### `store(Request $request, InventoryItem $inventoryItem): RedirectResponse`

`POST /inventory/{inventoryItem}/movements`. Movements are allowed
against archived items too (writing off or using up remaining stock is
legitimate) — no `active` guard.

1. Validates:
   - `type` — `required`, `Rule::in(StockMovement::TYPES)`
   - `quantity` — `required`, `integer`, `min:1`, `max:1000000` — a
     **positive magnitude**; the controller assigns the sign
   - `direction` — `required_if:type,adjustment`, `Rule::in(['increase', 'decrease'])`
   - `unit_cost` — `nullable`, `numeric`, `min:0`, `max:99999999.99`
   - `reason` — `required_if:type,adjustment`, `nullable`, `string`, `max:255`
   - `occurred_on` — `nullable`, `date`
2. Resolves the signed quantity:
   - `received` → `+q`
   - `consumed`, `expired` → `-q`
   - `adjustment` → `+q` when `direction === 'increase'`, else `-q`
3. Under `DB::transaction` + `InventoryItem::whereKey(...)->lockForUpdate()`:
   load the locked item's `movements`, compute `on_hand`. If
   `on_hand + signedQuantity < 0`, throw a `ValidationException` on
   `quantity`: *"Only N {unit} in stock."*
4. Creates the `stock_movements` row: `type`, signed `quantity`,
   `unit_cost` = the value **only when `type === 'received'`** (else
   `null`), `reason`, `occurred_on` defaulting to `today()`,
   `created_by = $request->user()->id` (direct assignment).
5. `return back()`.

No `update`, no `destroy`, no other methods. Docblock states the
append-only stance, mirroring `PaymentController`.

## `DashboardController` additions

Adds an `inventory` prop:

```php
$activeItems = InventoryItem::where('active', true)
    ->withSum('movements as on_hand', 'quantity')
    ->get();

'inventory' => [
    'low_count' => $activeItems
        ->filter(fn (InventoryItem $i) => (int) $i->on_hand <= $i->reorder_threshold)
        ->count(),
    'expiring_count' => $activeItems
        ->filter(fn (InventoryItem $i) => $i->isExpiringSoon())
        ->count(),
],
```

`low_count` covers out-of-stock too (`on_hand <= 0 <= threshold`).
`Dashboard.jsx` renders one tile beside the existing recall list and
outstanding-balances tile: **"N items low on stock · M expiring soon"**,
linking to `route('inventory.index', { filter: 'low' })`. When both
counts are `0` the tile reads "Stock levels healthy."

## Frontend

Both pages use `AuthenticatedLayout`. All confirmations and forms are
**in-page modal components** (the pattern the invoice/prescription tabs
use) — no `window.confirm` / `alert`, per the repo's browser-dialog
constraint.

### `resources/js/Pages/Inventory/Index.jsx`

Props: `items`, `filters`. Renders:

- **Filter tabs** — All / Low stock / Expiring / Archived, each an
  Inertia `<Link>` to `route('inventory.index', { filter, search })`
  with `preserveState` / `preserveScroll` / `replace`.
- **Search box** — text input, debounced (~300ms), issues
  `router.get(route('inventory.index'), { filter, search }, {
  preserveState: true, preserveScroll: true, replace: true })`.
- **`[+ New item]`** → modal: name, category `<select>` (CATEGORIES),
  unit text input + `<datalist>` (common-units list), reorder threshold
  number, supplier text, expiry date, notes textarea, and an optional
  **opening quantity** number. Submits `POST inventory.store`; on
  success Inertia follows the redirect to the item page.
- **Table** — name (links to `route('inventory.show', id)`) · category
  (humanised) · on-hand + unit · reorder threshold · **status badge**
  (`ok` green "OK" / `low` amber "Low" / `out` red "Out") · expiry date
  (amber text when `is_expiring_soon`, "—" when null) · supplier.
- **Empty state** per filter ("No items low on stock.", "Nothing
  expiring in the next 30 days.", "No archived items.", and for `all`
  with no items: a first-run "Add your first item" prompt).

### `resources/js/Pages/Inventory/Show.jsx`

Prop: `item`. Sections:

- **Header** — name, humanised category, status badge, on-hand shown
  large (`{on_hand} {unit}`). An amber "Expiring {date}" chip when
  `is_expiring_soon`. A grey "Archived" banner when `!active`.
- **Details panel** — unit, reorder threshold, supplier, expiry date,
  notes, created date + `creator_name`. An **`[Edit details]`** button
  opens a modal with the same fields as the create form (minus opening
  quantity), pre-filled, submitting `PATCH inventory.update`.
- **Actions** — `[Archive]` (when `active`) / `[Restore]` (when not),
  each a small confirm modal, submitting `PATCH inventory.update` with
  `{ active: false | true }`.
- **`[Record movement]`** → modal:
  - type `<select>` — Received / Consumed / Adjustment / Expired
  - quantity number (min 1)
  - **direction** radio (Increase / Decrease) — shown only when type is
    Adjustment
  - **unit cost** (₱) number — shown only when type is Received
  - occurred-on date (defaults to today)
  - reason text — shown always, **required** when type is Adjustment
    (label notes "required for adjustments")
  - submits `POST inventory-movements.store`; a rejected overdraw shows
    the server's "Only N …" message on the quantity field.
- **Movement history** — table: date (`occurred_on`) · type badge ·
  quantity (rendered `+N` green / `−N` red) · unit cost (`formatPeso`,
  only on received rows, else "—") · reason · recorded-by. Newest
  first. Empty state when the item has no movements.

### `resources/js/Pages/Dashboard.jsx`

Adds one tile in the existing tile row: low/expiring counts or "Stock
levels healthy", linking to `route('inventory.index', { filter: 'low' })`.
Matches the visual treatment of the outstanding-balances tile.

### `resources/js/Layouts/AuthenticatedLayout.jsx`

A `<NavLink>` (desktop) and `<ResponsiveNavLink>` (mobile), text
"Inventory", `href={route('inventory.index')}`,
`active={route().current('inventory.*')}`, placed **after** the
"Reports" links.

### Formatters

`Inventory/Index.jsx` and `Inventory/Show.jsx` import `formatDate` /
`formatPeso` from `resources/js/Pages/Patients/format.js` — the
established shared home for these formatters since the prescriptions
slice. The signed-quantity rendering (`+N` / `−N`) is a one-line inline
helper on the page, not a shared formatter. No new formatter file.

## Testing

### `tests/Feature/InventoryItemTest.php`

- **Auth** — a guest hitting `GET /inventory`, `GET /inventory/{item}`,
  `POST /inventory`, `PATCH /inventory/{item}` is redirected to login.
- **Create** — a valid `POST /inventory` creates an `active` item;
  `created_by` is the authenticated user even when the body carries a
  different `created_by`; `active` in the body is ignored (always
  `true` on create).
- **Create with opening quantity** — `opening_quantity: 40` creates the
  item plus one `adjustment` movement of `+40` with reason "Opening
  balance"; the item's `on_hand` is `40`. `opening_quantity: 0` or
  omitted creates no movement.
- **Create validation** — missing `name` / missing `unit` / a
  `category` outside `CATEGORIES` / a negative `reorder_threshold` /
  a negative `opening_quantity` each rejected; no item created.
- **Show** — returns the item detail, `on_hand`, `stock_status`, and
  the `movements` array with `creator_name`.
- **Update** — `PATCH` edits `name`, `category`, `unit`,
  `reorder_threshold`, `supplier`, `expiry_date`, `notes`; a partial
  payload leaves the untouched fields alone.
- **Archive / restore** — `PATCH { active: false }` removes the item
  from the default (`all`) index and from the dashboard counts, but it
  still appears under `?filter=archived` with its history intact;
  `PATCH { active: true }` restores it.
- **Index filters** — `?filter=low` returns only active items with
  `on_hand <= reorder_threshold`; `?filter=expiring` only active items
  with an `expiry_date` within 30 days (a past date is included, a date
  40 days out is not) — asserted with `Carbon::setTestNow()`;
  `?filter=archived` only inactive; default `all` excludes archived; an
  invalid `filter` value is rejected.
- **Index search** — `?search=glove` matches "Nitrile Gloves"
  case-insensitively and combines with the active `filter`.
- **on-hand math** — an item with `received +20` and `consumed -5` has
  `on_hand` 15; drops to "Low" once `on_hand <= reorder_threshold` and
  "Out" once `on_hand <= 0`.
- **Dashboard prop** — `inventory.low_count` counts low **and**
  out-of-stock active items; `inventory.expiring_count` counts active
  items expiring within 30 days; archived items are in neither.
- **No destroy route** — asserts no route named `inventory.destroy`.

### `tests/Feature/StockMovementTest.php`

- **Auth** — a guest `POST /inventory/{item}/movements` redirects to
  login.
- **Received** — creates a `+quantity` movement with `created_by` = the
  auth user; `unit_cost` is stored; `occurred_on` defaults to today
  when omitted and is respected when supplied; `on_hand` rises.
- **Consumed** — creates a `-quantity` movement; `on_hand` falls; any
  `unit_cost` in the body is ignored (stored `null`).
- **Consumed beyond stock** — an item with `on_hand` 5 rejecting a
  `consumed` of 8 with a message naming the available quantity; no
  movement row created; `on_hand` unchanged.
- **Expired** — creates a `-quantity` movement (`unit_cost` null even
  if supplied).
- **Adjustment** — `direction: increase` stores `+q`; `direction:
  decrease` stores `-q`; a `decrease` that would overdraw is rejected;
  a missing `reason` is rejected; a missing `direction` is rejected.
- **Type / quantity validation** — a `type` outside `TYPES` rejected; a
  `quantity` of `0` or negative rejected.
- **Append-only** — asserts no route named
  `inventory-movements.update` / `inventory-movements.destroy`, and
  `StockMovementController` exposes no such methods.

### Factories

- `InventoryItemFactory` — `name` a realistic supply name, `category`
  random from `CATEGORIES`, `unit` random from a small set, `supplier`
  a company name, `reorder_threshold` `fake()->numberBetween(2, 10)`,
  `expiry_date` `null`, `active` `true`, `created_by` from
  `User::factory()`. States: `archived()` (`active` false),
  `expiringSoon()` (`expiry_date` = `now()->addDays(rand(1, 20))`).
- `StockMovementFactory` — `inventory_item_id` from
  `InventoryItem::factory()`, `type` `received`, `quantity`
  `numberBetween(10, 60)`, `unit_cost` a realistic peso figure,
  `reason` `null`, `occurred_on` `now()`, `created_by` from
  `User::factory()`. States: `consumed()` (`type` consumed, negative
  `quantity`, `unit_cost` null), `expired()` (likewise), `adjustment()`
  (`type` adjustment, `reason` set).

### DemoSeeder additions

Additive block after the reporting fixtures, reusing the existing
`$staff` user for `created_by`. Creates ~12 named dental items across
the categories (e.g. "Nitrile Gloves (M)" ppe, "Composite Resin A2"
consumable, "Lidocaine 2% Cartridges" medication, "Prophy Paste"
consumable, "Alginate Impression Material" lab_material, "Assorted
Diamond Burs" instrument, "Cotton Rolls" consumable, "Surgical Face
Masks" ppe, "Fluoride Varnish" medication, "Autoclave Pouches"
consumable, "Disposable Saliva Ejectors" consumable, "Patient Bibs"
ppe). For each: a `received` movement 1–4 months ago and one or two
`consumed` movements bringing it to a mid-level count. Then tune the
fixtures so **two items sit at/below their threshold**, **one has an
`expiry_date` ~15 days out**, and **one is archived**.

### Full-suite gate

`"$HOME/.config/herd/bin/php.bat" artisan test` — all pre-existing
tests plus the new files pass. `npm run build` succeeds (Vite manifest
for the two new pages; a fresh worktree needs the build before the
page-render tests pass — see CLAUDE.md / memory).

## Out of scope / explicitly not addressed here

Deferred to future phases per `docs/PLATFORM_VISION.md`:

- **Purchase orders, a receiving/approval workflow, a supplier
  directory** — `supplier` is a free-text string; a `received` movement
  is a one-step manual record.
- **Batch / lot tracking and FEFO drawdown** — expiry is one
  item-level date.
- **Fractional quantities and unit conversions** — integers only.
- **Inventory valuation, cost-of-goods, purchase-spend reporting** —
  `unit_cost` is captured but not aggregated anywhere. A Reports
  follow-up could add it without a migration.
- **Linking stock consumption to an appointment / treatment / patient
  / invoice** — the module is deliberately standalone.
- **Multi-location / per-room stock, stock transfers.**
- **Automatic reorder / PO generation, par-level suggestions beyond the
  flat threshold.**
- **Barcode / QR scanning.**
- **Emailed or SMS low-stock alerts** — in-app tile only (Hard
  constraints).
- **Editing or deleting a stock movement** — append-only; correct with
  a compensating `adjustment`.
- **Pagination / DB-side search on `/inventory`** — consistent with the
  other unpaginated index pages; Known gaps.
- **Any change to existing patient / appointment / dental-record /
  tooth-condition / treatment-plan / prescription / invoice / payment /
  reports behaviour.**

## Known gaps (to add to `CLAUDE.md`)

- Inventory on-hand is re-derived (`SUM(stock_movements.quantity)`) on
  every read — the index, the item page, and the dashboard tile. No
  cached column. Same accepted O(n) pattern as invoice balances; a
  multi-year dataset would want a cached quantity or a periodic
  snapshot.
- `/inventory` loads every item (with a movement-sum subquery) and
  filters/searches in PHP — no pagination, same as `patients.index` /
  `invoices.index`.
- Inventory expiry is a single item-level `expiry_date`, not
  per-batch/lot. A clinic holding multiple lots of one item with
  different expiries can't represent that; FEFO batch tracking is a
  future slice.
- Stock quantities are integers — no fractional units (e.g.
  millilitres). The free-text `unit` may read "ml" but movements are
  whole numbers.
- `InventoryItem::CATEGORIES`, `StockMovement::TYPES`, and the
  frontend-only common-units `<datalist>` list are duplicated in the
  React `<select>`s — the same docblock-sync situation the
  appointment / treatment / invoice status sets already carry.
- `stock_movements.unit_cost` is captured on `received` movements but
  nothing aggregates it — no valuation or purchase-spend reporting yet.
- Movement overdraw protection is a check-then-act under a row lock
  (like `PaymentController`); correct for single-node, and the lock
  makes concurrent movements on one item safe.
- Expiry/low-stock counts on the dashboard load every active item and
  its movement sum on each dashboard render — one more consumer of the
  same unbounded pattern already noted for `Patient::dueForRecall()`
  and the outstanding-invoices tile.
```
