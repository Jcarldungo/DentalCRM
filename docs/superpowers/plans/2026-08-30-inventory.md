# Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a standalone staff-facing inventory module — items plus an append-only stock-movement ledger — with a `/inventory` index, an item page, and a dashboard tile.

**Architecture:** An `inventory_items` table holds mutable item records; a `stock_movements` table is an append-only ledger. An item's on-hand quantity is the signed `SUM(quantity)` of its movements — never stored — exactly as invoice balances derive from payments. Two controllers (`InventoryItemController` with index/show/store/update, `StockMovementController` with store only), five routes, two Inertia pages.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, MariaDB (tests: `dentalcrm_testing`), Herd PHP 8.4. PHPUnit feature tests with `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-08-30-inventory-design.md`

## Global Constraints

- Run PHP tooling via `"$HOME/.config/herd/bin/php.bat"` (e.g. `"$HOME/.config/herd/bin/php.bat" artisan test`). `npm` is on PATH.
- Tests are **flat**: `tests/Feature/<Name>Test.php`, no subdirectories.
- Staff-facing controllers live in `App\Http\Controllers\Admin\`.
- Inertia staff pages under `resources/js/Pages/`, using `AuthenticatedLayout`.
- **No RBAC** — every authenticated user can do everything. All new routes sit in the `Route::middleware('auth')` group in `routes/web.php`.
- **Nothing is transmitted** — low-stock/expiry surface in-app only. No mail, no SMS.
- **Stock movements are append-only** — no update/destroy method or route, ever.
- **On-hand can never go negative** — enforced under a row lock in `StockMovementController`.
- Money columns are `decimal(10,2)`, pesos (`₱`).
- `created_by` is **excluded from `$fillable`** on both models and set server-side from `$request->user()->id`.
- Consts `InventoryItem::CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office']` and `StockMovement::TYPES = ['received', 'consumed', 'adjustment', 'expired']`.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump()`, no unused imports, no commented-out code.
- Commits carry **NO** `Co-Authored-By` trailer. Short imperative subjects. One commit per task.
- After any task that adds/changes a `.jsx` page, run `npm run build` and confirm it succeeds (the Vite manifest is needed for the feature tests that render the root blade).

---

### Task 1: Data layer — migrations, models, factories

**Files:**
- Create: `database/migrations/2026_08_30_140000_create_inventory_items_table.php`
- Create: `database/migrations/2026_08_30_141000_create_stock_movements_table.php`
- Create: `app/Models/InventoryItem.php`
- Create: `app/Models/StockMovement.php`
- Create: `database/factories/InventoryItemFactory.php`
- Create: `database/factories/StockMovementFactory.php`
- Test: `tests/Feature/InventoryItemTest.php` (new — model-behaviour tests only in this task)

**Interfaces:**
- Produces:
  - `App\Models\InventoryItem` — `$fillable = ['name','category','unit','reorder_threshold','supplier','expiry_date','notes','active']`; consts `CATEGORIES`; casts `expiry_date`→`date:Y-m-d`, `active`→`boolean`, `reorder_threshold`→`integer`.
    - `movements(): HasMany` (ordered `occurred_on` then `id`)
    - `creator(): BelongsTo` (`User`, `created_by`)
    - `onHand(): int` — `(int) $this->movements->sum('quantity')` (expects `movements` loaded)
    - `isLow(): bool` — `$this->onHand() <= $this->reorder_threshold`
    - `isExpiringSoon(int $days = 30): bool` — `expiry_date` set and `<= now()->addDays($days)`
    - `stockStatus(): string` — `'out'` (on-hand ≤ 0) / `'low'` (≤ threshold) / `'ok'`
  - `App\Models\StockMovement` — `$fillable = ['inventory_item_id','type','quantity','unit_cost','reason','occurred_on']`; const `TYPES`; casts `quantity`→`integer`, `unit_cost`→`decimal:2`, `occurred_on`→`date:Y-m-d`.
    - `item(): BelongsTo` (`InventoryItem`)
    - `creator(): BelongsTo` (`User`, `created_by`)
  - `InventoryItemFactory` — states `archived()`, `expiringSoon()`
  - `StockMovementFactory` — default `type: 'received'` positive `quantity`; states `consumed()`, `expired()` (both negative `quantity`, `unit_cost` null), `adjustment()` (`reason` set)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InventoryItemTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_on_hand_is_the_signed_sum_of_movements(): void
    {
        $item = InventoryItem::factory()->create(['reorder_threshold' => 5]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 20]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -5]);

        $item->load('movements');

        $this->assertSame(15, $item->onHand());
        $this->assertFalse($item->isLow());
        $this->assertSame('ok', $item->stockStatus());
    }

    public function test_stock_status_moves_from_ok_to_low_to_out(): void
    {
        $item = InventoryItem::factory()->create(['reorder_threshold' => 5]);
        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 5]);
        $item->load('movements');
        $this->assertSame('low', $item->stockStatus());

        StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -5]);
        $item->load('movements');
        $this->assertSame('out', $item->stockStatus());
    }

    public function test_is_expiring_soon_uses_a_30_day_window(): void
    {
        Carbon::setTestNow('2026-08-30');

        $this->assertTrue((new InventoryItem(['expiry_date' => '2026-09-10']))->isExpiringSoon());
        $this->assertTrue((new InventoryItem(['expiry_date' => '2026-08-01']))->isExpiringSoon());
        $this->assertFalse((new InventoryItem(['expiry_date' => '2026-10-15']))->isExpiringSoon());
        $this->assertFalse((new InventoryItem(['expiry_date' => null]))->isExpiringSoon());

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: FAIL — `Class "App\Models\InventoryItem" not found`.

- [ ] **Step 3: Create the `inventory_items` migration**

`database/migrations/2026_08_30_140000_create_inventory_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->string('unit', 20);
            $table->unsignedInteger('reorder_threshold')->default(0);
            $table->string('supplier')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
```

- [ ] **Step 4: Create the `stock_movements` migration**

`database/migrations/2026_08_30_141000_create_stock_movements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->date('occurred_on');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
```

- [ ] **Step 5: Create the `InventoryItem` model**

`app/Models/InventoryItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stocked clinic supply. Item fields are mutable for the life of the
 * item; `active = false` archives it (no hard delete). The current
 * on-hand quantity is DERIVED from the append-only stock_movements
 * ledger — never stored. See
 * docs/superpowers/specs/2026-08-30-inventory-design.md.
 */
class InventoryItem extends Model
{
    use HasFactory;

    public const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];

    protected $fillable = [
        'name',
        'category',
        'unit',
        'reorder_threshold',
        'supplier',
        'expiry_date',
        'notes',
        'active',
    ];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'active' => 'boolean',
        'reorder_threshold' => 'integer',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderBy('occurred_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Signed sum of the loaded movements. Expects `movements` to be loaded. */
    public function onHand(): int
    {
        return (int) $this->movements->sum('quantity');
    }

    public function isLow(): bool
    {
        return $this->onHand() <= $this->reorder_threshold;
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->lessThanOrEqualTo(now()->addDays($days));
    }

    public function stockStatus(): string
    {
        $onHand = $this->onHand();

        return match (true) {
            $onHand <= 0 => 'out',
            $onHand <= $this->reorder_threshold => 'low',
            default => 'ok',
        };
    }
}
```

- [ ] **Step 6: Create the `StockMovement` model**

`app/Models/StockMovement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable stock movement. `quantity` is SIGNED — positive for
 * `received` and increasing `adjustment`, negative for `consumed`,
 * `expired`, and decreasing `adjustment`. Append-only: there is no
 * update or destroy path anywhere. See
 * docs/superpowers/specs/2026-08-30-inventory-design.md.
 */
class StockMovement extends Model
{
    use HasFactory;

    public const TYPES = ['received', 'consumed', 'adjustment', 'expired'];

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'unit_cost',
        'reason',
        'occurred_on',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'occurred_on' => 'date:Y-m-d',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 7: Create `InventoryItemFactory`**

`database/factories/InventoryItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Nitrile Gloves', 'Composite Resin', 'Prophy Paste', 'Cotton Rolls', 'Face Masks', 'Suction Tips', 'Autoclave Pouches'])
                .' '.$this->faker->randomElement(['(S)', '(M)', '(L)', 'A2', 'Fine', 'Bulk']),
            'category' => $this->faker->randomElement(InventoryItem::CATEGORIES),
            'unit' => $this->faker->randomElement(['box', 'piece', 'pair', 'pack', 'bottle', 'tube']),
            'reorder_threshold' => $this->faker->numberBetween(2, 10),
            'supplier' => $this->faker->company(),
            'expiry_date' => null,
            'notes' => null,
            'active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => [
            'expiry_date' => now()->addDays($this->faker->numberBetween(1, 20))->toDateString(),
        ]);
    }
}
```

- [ ] **Step 8: Create `StockMovementFactory`**

`database/factories/StockMovementFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'type' => 'received',
            'quantity' => $this->faker->numberBetween(10, 60),
            'unit_cost' => $this->faker->randomElement([50, 120, 250, 500]),
            'reason' => null,
            'occurred_on' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'type' => 'consumed',
            'quantity' => -$this->faker->numberBetween(1, 8),
            'unit_cost' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'type' => 'expired',
            'quantity' => -$this->faker->numberBetween(1, 5),
            'unit_cost' => null,
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn () => [
            'type' => 'adjustment',
            'quantity' => $this->faker->numberBetween(1, 3),
            'unit_cost' => null,
            'reason' => 'Stock count correction',
        ]);
    }
}
```

- [ ] **Step 9: Run migrations and the test**

Run: `"$HOME/.config/herd/bin/php.bat" artisan migrate` then `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: PASS (3 tests). (`RefreshDatabase` re-migrates the `dentalcrm_testing` DB automatically; the explicit `migrate` keeps the dev DB current — accept its default connection, do not pass `--database`.)

- [ ] **Step 10: Commit**

```bash
git add app/Models/InventoryItem.php app/Models/StockMovement.php \
  database/migrations/2026_08_30_140000_create_inventory_items_table.php \
  database/migrations/2026_08_30_141000_create_stock_movements_table.php \
  database/factories/InventoryItemFactory.php database/factories/StockMovementFactory.php \
  tests/Feature/InventoryItemTest.php
git commit -m "Add inventory item and stock movement models"
```

---

### Task 2: Read side — `InventoryItemController` index & show

**Files:**
- Create: `app/Http/Controllers/Admin/InventoryItemController.php`
- Modify: `routes/web.php` (add `inventory.index` + `inventory.show` in the `auth` group, after the `/invoices` routes)
- Test: `tests/Feature/InventoryItemTest.php` (add methods)

**Interfaces:**
- Consumes: `InventoryItem`, `StockMovement` models from Task 1.
- Produces:
  - Route `GET /inventory` → `inventory.index` → renders `Inventory/Index` with:
    - `items`: `[{ id, name, category, unit, on_hand:int, reorder_threshold:int, stock_status:'ok'|'low'|'out', supplier:string|null, expiry_date:'Y-m-d'|null, is_expiring_soon:bool, active:bool }]`
    - `filters`: `{ filter:'all'|'low'|'expiring'|'archived', search:string|null }`
  - Route `GET /inventory/{inventoryItem}` → `inventory.show` → renders `Inventory/Show` with `item`: the above fields plus `notes`, `created_at` (ISO), `creator_name`, `on_hand`, `stock_status`, and `movements: [{ id, type, quantity:int(signed), unit_cost:float|null, reason:string|null, occurred_on:'Y-m-d', created_at:ISO, creator_name }]` **newest-first**.
  - `InventoryItemController` protected helper `present(InventoryItem $item): array` used by `show()` and reused by `store()`/`update()` redirects in Task 3.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InventoryItemTest.php`:

```php
public function test_guest_cannot_view_inventory(): void
{
    $item = InventoryItem::factory()->create();

    $this->get(route('inventory.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.show', $item))->assertRedirect(route('login'));
}

public function test_index_lists_active_items_with_computed_stock(): void
{
    $this->actingUser();
    $item = InventoryItem::factory()->create(['name' => 'Nitrile Gloves', 'reorder_threshold' => 5]);
    StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 3]);

    $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page
        ->component('Inventory/Index')
        ->has('items', 1)
        ->where('items.0.name', 'Nitrile Gloves')
        ->where('items.0.on_hand', 3)
        ->where('items.0.stock_status', 'low'));
}

public function test_index_filters_low_expiring_and_archived(): void
{
    Carbon::setTestNow('2026-08-30');
    $this->actingUser();

    $healthy = InventoryItem::factory()->create(['reorder_threshold' => 1]);
    StockMovement::factory()->create(['inventory_item_id' => $healthy->id, 'quantity' => 50]);

    $low = InventoryItem::factory()->create(['reorder_threshold' => 10]);
    StockMovement::factory()->create(['inventory_item_id' => $low->id, 'quantity' => 2]);

    $expiring = InventoryItem::factory()->create(['expiry_date' => '2026-09-05', 'reorder_threshold' => 0]);
    StockMovement::factory()->create(['inventory_item_id' => $expiring->id, 'quantity' => 30]);

    $archived = InventoryItem::factory()->archived()->create();

    $this->get(route('inventory.index', ['filter' => 'low']))
        ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $low->id));
    $this->get(route('inventory.index', ['filter' => 'expiring']))
        ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $expiring->id));
    $this->get(route('inventory.index', ['filter' => 'archived']))
        ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $archived->id));
    $this->get(route('inventory.index'))
        ->assertInertia(fn ($page) => $page->has('items', 3));

    Carbon::setTestNow();
}

public function test_index_search_matches_name(): void
{
    $this->actingUser();
    InventoryItem::factory()->create(['name' => 'Nitrile Gloves']);
    InventoryItem::factory()->create(['name' => 'Face Masks']);

    $this->get(route('inventory.index', ['search' => 'glove']))
        ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.name', 'Nitrile Gloves'));
}

public function test_index_rejects_an_unknown_filter(): void
{
    $this->actingUser();
    $this->get(route('inventory.index', ['filter' => 'bogus']))->assertSessionHasErrors('filter');
}

public function test_show_projects_the_item_with_movements_newest_first(): void
{
    $this->actingUser();
    $item = InventoryItem::factory()->create();
    StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'received', 'quantity' => 20, 'occurred_on' => '2026-08-01']);
    StockMovement::factory()->create(['inventory_item_id' => $item->id, 'type' => 'consumed', 'quantity' => -4, 'occurred_on' => '2026-08-20']);

    $this->get(route('inventory.show', $item))->assertInertia(fn ($page) => $page
        ->component('Inventory/Show')
        ->where('item.on_hand', 16)
        ->has('item.movements', 2)
        ->where('item.movements.0.quantity', -4)
        ->where('item.movements.1.quantity', 20));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: the new tests FAIL with `Route [inventory.index] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`: add the import near the other `Admin` imports —

```php
use App\Http\Controllers\Admin\InventoryItemController;
```

and inside the `Route::middleware('auth')->group(...)`, immediately after the five `/invoices` route lines:

```php
    Route::get('/inventory', [InventoryItemController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/{inventoryItem}', [InventoryItemController::class, 'show'])->name('inventory.show');
```

(The `store` / `update` / movements routes are added in Tasks 3 and 4.)

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/Admin/InventoryItemController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventory items and their derived stock levels. index() and show()
 * project on-hand / status from the append-only stock_movements ledger.
 * store() / update() (Task 3) manage the mutable item record; there is
 * no destroy() — retiring an item means setting active = false.
 */
class InventoryItemController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'low', 'expiring', 'archived'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $filter = $validated['filter'] ?? 'all';
        $search = $validated['search'] ?? null;

        $items = InventoryItem::query()
            ->withSum('movements as on_hand', 'quantity')
            ->orderBy('name')
            ->get()
            ->filter(function (InventoryItem $item) use ($filter) {
                $onHand = (int) $item->on_hand;

                return match ($filter) {
                    'low' => $item->active && $onHand <= $item->reorder_threshold,
                    'expiring' => $item->active && $item->isExpiringSoon(),
                    'archived' => ! $item->active,
                    default => $item->active,
                };
            })
            ->when($search !== null, fn ($collection) => $collection->filter(
                fn (InventoryItem $item) => str_contains(mb_strtolower($item->name), mb_strtolower($search)),
            ))
            ->values()
            ->map(function (InventoryItem $item) {
                $onHand = (int) $item->on_hand;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'on_hand' => $onHand,
                    'reorder_threshold' => $item->reorder_threshold,
                    'stock_status' => $onHand <= 0 ? 'out' : ($onHand <= $item->reorder_threshold ? 'low' : 'ok'),
                    'supplier' => $item->supplier,
                    'expiry_date' => $item->expiry_date?->toDateString(),
                    'is_expiring_soon' => $item->isExpiringSoon(),
                    'active' => $item->active,
                ];
            });

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    public function show(InventoryItem $inventoryItem): Response
    {
        $inventoryItem->load(['movements.creator', 'creator']);

        return Inertia::render('Inventory/Show', [
            'item' => $this->present($inventoryItem),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'unit' => $item->unit,
            'reorder_threshold' => $item->reorder_threshold,
            'supplier' => $item->supplier,
            'notes' => $item->notes,
            'expiry_date' => $item->expiry_date?->toDateString(),
            'is_expiring_soon' => $item->isExpiringSoon(),
            'active' => $item->active,
            'on_hand' => $item->onHand(),
            'stock_status' => $item->stockStatus(),
            'created_at' => $item->created_at->toIso8601String(),
            'creator_name' => $item->creator->name,
            'movements' => $item->movements->reverse()->values()->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'unit_cost' => $movement->unit_cost !== null ? (float) $movement->unit_cost : null,
                'reason' => $movement->reason,
                'occurred_on' => $movement->occurred_on->toDateString(),
                'created_at' => $movement->created_at->toIso8601String(),
                'creator_name' => $movement->creator->name,
            ]),
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: PASS (all Task 1 + Task 2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/InventoryItemController.php routes/web.php tests/Feature/InventoryItemTest.php
git commit -m "Add the inventory index and item pages"
```

---

### Task 3: Write side — `InventoryItemController` store & update

**Files:**
- Modify: `app/Http/Controllers/Admin/InventoryItemController.php` (add `store`, `update`, imports)
- Modify: `routes/web.php` (add `inventory.store` + `inventory.update`)
- Test: `tests/Feature/InventoryItemTest.php` (add methods)

**Interfaces:**
- Consumes: `present()` helper and routes from Task 2.
- Produces:
  - `POST /inventory` → `inventory.store` — validates `name` (required, max 255), `category` (`Rule::in(CATEGORIES)`), `unit` (required, max 20), `reorder_threshold` (nullable int 0..1000000), `supplier` (nullable, max 255), `expiry_date` (nullable date), `notes` (nullable), `opening_quantity` (nullable int 0..1000000). Creates the item with `active = true`, `created_by` from the auth user. If `opening_quantity > 0`, creates one `adjustment` movement (`quantity` = opening, `reason` "Opening balance", `occurred_on` today) in the same transaction. Redirects to `inventory.show`.
  - `PATCH /inventory/{inventoryItem}` → `inventory.update` — all fields `sometimes`; `active` (`sometimes|boolean`) is how archive/restore is done. `reorder_threshold` explicitly `null` is coerced to `0`. Redirects `back()`.
  - No `destroy` — asserted absent.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InventoryItemTest.php`:

```php
public function test_it_creates_an_item_owned_by_the_current_user(): void
{
    $user = $this->actingUser();
    $other = User::factory()->create();

    $this->post(route('inventory.store'), [
        'name' => 'Composite Resin A2',
        'category' => 'consumable',
        'unit' => 'syringe',
        'reorder_threshold' => 3,
        'created_by' => $other->id,
        'active' => false,
    ])->assertRedirect();

    $item = InventoryItem::sole();
    $this->assertSame('Composite Resin A2', $item->name);
    $this->assertSame($user->id, $item->created_by);
    $this->assertTrue($item->active);
}

public function test_opening_quantity_creates_an_adjustment_movement(): void
{
    $this->actingUser();

    $this->post(route('inventory.store'), [
        'name' => 'Cotton Rolls',
        'category' => 'consumable',
        'unit' => 'box',
        'opening_quantity' => 40,
    ])->assertRedirect();

    $item = InventoryItem::sole();
    $movement = $item->movements()->sole();
    $this->assertSame('adjustment', $movement->type);
    $this->assertSame(40, $movement->quantity);
    $this->assertSame('Opening balance', $movement->reason);

    $item->load('movements');
    $this->assertSame(40, $item->onHand());
}

public function test_opening_quantity_of_zero_creates_no_movement(): void
{
    $this->actingUser();

    $this->post(route('inventory.store'), [
        'name' => 'Face Masks',
        'category' => 'ppe',
        'unit' => 'box',
        'opening_quantity' => 0,
    ]);

    $this->assertSame(0, StockMovement::count());
}

public function test_create_validation_blocks_bad_input(): void
{
    $this->actingUser();

    $this->post(route('inventory.store'), ['category' => 'consumable', 'unit' => 'box'])
        ->assertSessionHasErrors('name');
    $this->post(route('inventory.store'), ['name' => 'X', 'category' => 'nope', 'unit' => 'box'])
        ->assertSessionHasErrors('category');
    $this->post(route('inventory.store'), ['name' => 'X', 'category' => 'consumable', 'unit' => 'box', 'reorder_threshold' => -1])
        ->assertSessionHasErrors('reorder_threshold');

    $this->assertSame(0, InventoryItem::count());
}

public function test_it_updates_item_fields(): void
{
    $this->actingUser();
    $item = InventoryItem::factory()->create(['name' => 'Old', 'reorder_threshold' => 2]);

    $this->patch(route('inventory.update', $item), ['name' => 'New', 'reorder_threshold' => 8])
        ->assertRedirect();

    $item->refresh();
    $this->assertSame('New', $item->name);
    $this->assertSame(8, $item->reorder_threshold);
}

public function test_archiving_hides_an_item_then_restoring_shows_it(): void
{
    $this->actingUser();
    $item = InventoryItem::factory()->create();

    $this->patch(route('inventory.update', $item), ['active' => false]);
    $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page->has('items', 0));
    $this->get(route('inventory.index', ['filter' => 'archived']))
        ->assertInertia(fn ($page) => $page->has('items', 1));

    $this->patch(route('inventory.update', $item), ['active' => true]);
    $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page->has('items', 1));
}

public function test_there_is_no_inventory_destroy_route(): void
{
    $this->assertFalse(Route::has('inventory.destroy'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: the new tests FAIL with `Route [inventory.store] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, directly under the two `inventory` GET routes from Task 2:

```php
    Route::post('/inventory', [InventoryItemController::class, 'store'])->name('inventory.store');
    Route::patch('/inventory/{inventoryItem}', [InventoryItemController::class, 'update'])->name('inventory.update');
```

(Keep `GET /inventory/{inventoryItem}` last among the `/inventory` GETs so `POST /inventory` is unambiguous — order of the three lines: `GET /inventory`, `POST /inventory`, `PATCH /inventory/{inventoryItem}`, `GET /inventory/{inventoryItem}`.)

- [ ] **Step 4: Add `store()` and `update()` to the controller**

Add these imports to `InventoryItemController`:

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
```

Add the methods (after `show()`, before `present()`):

```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(InventoryItem::CATEGORIES)],
            'unit' => ['required', 'string', 'max:20'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'opening_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $userId = $request->user()->id;

        $item = DB::transaction(function () use ($validated, $userId) {
            $item = new InventoryItem([
                'name' => $validated['name'],
                'category' => $validated['category'],
                'unit' => $validated['unit'],
                'reorder_threshold' => $validated['reorder_threshold'] ?? 0,
                'supplier' => $validated['supplier'] ?? null,
                'expiry_date' => $validated['expiry_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
            $item->active = true;
            $item->created_by = $userId;
            $item->save();

            $opening = (int) ($validated['opening_quantity'] ?? 0);
            if ($opening > 0) {
                $movement = $item->movements()->make([
                    'type' => 'adjustment',
                    'quantity' => $opening,
                    'reason' => 'Opening balance',
                    'occurred_on' => now()->toDateString(),
                ]);
                $movement->created_by = $userId;
                $movement->save();
            }

            return $item;
        });

        return redirect()->route('inventory.show', $item);
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', Rule::in(InventoryItem::CATEGORIES)],
            'unit' => ['sometimes', 'required', 'string', 'max:20'],
            'reorder_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('reorder_threshold', $validated) && $validated['reorder_threshold'] === null) {
            $validated['reorder_threshold'] = 0;
        }

        $inventoryItem->update($validated);

        return back();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest`
Expected: PASS (all Task 1–3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/InventoryItemController.php routes/web.php tests/Feature/InventoryItemTest.php
git commit -m "Add inventory item create and edit"
```

---

### Task 4: `StockMovementController` — recording movements

**Files:**
- Create: `app/Http/Controllers/Admin/StockMovementController.php`
- Modify: `routes/web.php` (add `inventory-movements.store`)
- Test: `tests/Feature/StockMovementTest.php` (new)

**Interfaces:**
- Consumes: `InventoryItem`, `StockMovement`, the `inventory.show` route.
- Produces:
  - `POST /inventory/{inventoryItem}/movements` → `inventory-movements.store`. Validates `type` (`Rule::in(TYPES)`), `quantity` (`integer|min:1|max:1000000` — a positive magnitude), `direction` (`required_if:type,adjustment`, `in:increase,decrease`), `unit_cost` (`nullable|numeric|min:0|max:99999999.99`), `reason` (`required_if:type,adjustment`, `nullable|string|max:255`), `occurred_on` (`nullable|date`).
  - Signs the stored `quantity`: `received` → `+q`; `consumed`/`expired` → `-q`; `adjustment` → `+q`/`-q` by `direction`.
  - Under `lockForUpdate` on the item: rejects (`ValidationException` on `quantity`, message `"Only N {unit} in stock."`) any movement that would make on-hand `< 0`.
  - Stores `unit_cost` **only** when `type === 'received'` (else `null`). `created_by` from the auth user. `occurred_on` defaults to today.
  - No `update` / `destroy` method or route.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StockMovementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** An item with $onHand received into stock. */
    protected function stockedItem(int $onHand = 20, string $unit = 'box'): InventoryItem
    {
        $item = InventoryItem::factory()->create(['unit' => $unit]);

        if ($onHand !== 0) {
            StockMovement::factory()->create([
                'inventory_item_id' => $item->id,
                'type' => 'received',
                'quantity' => $onHand,
            ]);
        }

        return $item;
    }

    public function test_guest_cannot_record_a_movement(): void
    {
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), ['type' => 'consumed', 'quantity' => 1])
            ->assertRedirect(route('login'));
    }

    public function test_received_adds_stock_with_a_unit_cost(): void
    {
        $user = $this->actingUser();
        $item = $this->stockedItem(0);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 25,
            'unit_cost' => 12.50,
        ])->assertRedirect();

        $movement = StockMovement::sole();
        $this->assertSame(25, $movement->quantity);
        $this->assertSame('12.50', $movement->unit_cost);
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame(now()->toDateString(), $movement->occurred_on->toDateString());
    }

    public function test_occurred_on_is_respected_when_supplied(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(0);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'received',
            'quantity' => 5,
            'occurred_on' => '2026-08-20',
        ]);

        $this->assertSame('2026-08-20', StockMovement::sole()->occurred_on->toDateString());
    }

    public function test_consumed_subtracts_stock_and_ignores_unit_cost(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'consumed',
            'quantity' => 4,
            'unit_cost' => 99,
        ])->assertRedirect();

        $movement = StockMovement::where('type', 'consumed')->sole();
        $this->assertSame(-4, $movement->quantity);
        $this->assertNull($movement->unit_cost);

        $item->load('movements');
        $this->assertSame(6, $item->onHand());
    }

    public function test_expired_subtracts_stock(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), ['type' => 'expired', 'quantity' => 3]);

        $this->assertSame(-3, StockMovement::where('type', 'expired')->sole()->quantity);
    }

    public function test_a_movement_cannot_drive_stock_negative(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(5, 'pair');

        $this->post(route('inventory-movements.store', $item), ['type' => 'consumed', 'quantity' => 8])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1, $item->movements()->count());
    }

    public function test_adjustment_requires_direction_and_reason(): void
    {
        $this->actingUser();
        $item = $this->stockedItem(10);

        $this->post(route('inventory-movements.store', $item), ['type' => 'adjustment', 'quantity' => 2])
            ->assertSessionHasErrors(['direction', 'reason']);

        $this->post(route('inventory-movements.store', $item), [
            'type' => 'adjustment',
            'quantity' => 2,
            'direction' => 'decrease',
            'reason' => 'Miscount',
        ])->assertRedirect();

        $this->assertSame(-2, StockMovement::where('type', 'adjustment')->sole()->quantity);
    }

    public function test_type_and_quantity_are_validated(): void
    {
        $this->actingUser();
        $item = $this->stockedItem();

        $this->post(route('inventory-movements.store', $item), ['type' => 'bogus', 'quantity' => 1])
            ->assertSessionHasErrors('type');
        $this->post(route('inventory-movements.store', $item), ['type' => 'received', 'quantity' => 0])
            ->assertSessionHasErrors('quantity');
    }

    public function test_movements_are_append_only(): void
    {
        $this->assertFalse(Route::has('inventory-movements.update'));
        $this->assertFalse(Route::has('inventory-movements.destroy'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=StockMovementTest`
Expected: FAIL with `Route [inventory-movements.store] not defined`.

- [ ] **Step 3: Add the route**

In `routes/web.php`, after the `inventory.update` route:

```php
    Route::post('/inventory/{inventoryItem}/movements', [StockMovementController::class, 'store'])
        ->name('inventory-movements.store');
```

Add the import:

```php
use App\Http\Controllers\Admin\StockMovementController;
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/Admin/StockMovementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording a stock movement against an item, and nothing else.
 * Movements are append-only — there is deliberately no update() or
 * destroy() here, and no matching route. A movement that would drive
 * on-hand below zero is rejected under a row lock. `unit_cost` is kept
 * only for `received` movements.
 */
class StockMovementController extends Controller
{
    public function store(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(StockMovement::TYPES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'direction' => ['required_if:type,adjustment', Rule::in(['increase', 'decrease'])],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => ['required_if:type,adjustment', 'nullable', 'string', 'max:255'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $magnitude = (int) $validated['quantity'];
        $signed = match ($validated['type']) {
            'received' => $magnitude,
            'consumed', 'expired' => -$magnitude,
            'adjustment' => ($validated['direction'] ?? 'increase') === 'increase' ? $magnitude : -$magnitude,
        };

        $userId = $request->user()->id;

        DB::transaction(function () use ($inventoryItem, $validated, $signed, $userId) {
            $locked = InventoryItem::whereKey($inventoryItem->id)->lockForUpdate()->first();
            $locked->load('movements');
            $onHand = $locked->onHand();

            if ($onHand + $signed < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$onHand} {$locked->unit} in stock.",
                ]);
            }

            $movement = $locked->movements()->make([
                'type' => $validated['type'],
                'quantity' => $signed,
                'unit_cost' => $validated['type'] === 'received' ? ($validated['unit_cost'] ?? null) : null,
                'reason' => $validated['reason'] ?? null,
                'occurred_on' => $validated['occurred_on'] ?? now()->toDateString(),
            ]);
            $movement->created_by = $userId;
            $movement->save();
        });

        return back();
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=StockMovementTest`
Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/StockMovementController.php routes/web.php tests/Feature/StockMovementTest.php
git commit -m "Add stock movement recording"
```

---

### Task 5: Dashboard tile

**Files:**
- Modify: `app/Http/Controllers/Admin/DashboardController.php` (add `inventory` prop)
- Modify: `resources/js/Pages/Dashboard.jsx` (accept `inventory`, render a tile)
- Test: `tests/Feature/InventoryItemTest.php` (add one method)

**Interfaces:**
- Consumes: `InventoryItem` model, `inventory.index` route.
- Produces: `Dashboard` gets an `inventory` prop `{ low_count:int, expiring_count:int }` — active items only; `low_count` includes out-of-stock (`on_hand <= reorder_threshold`); `expiring_count` uses `isExpiringSoon()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/InventoryItemTest.php`:

```php
public function test_dashboard_reports_low_and_expiring_counts(): void
{
    Carbon::setTestNow('2026-08-30');
    $this->actingUser();

    $low = InventoryItem::factory()->create(['reorder_threshold' => 10]);
    StockMovement::factory()->create(['inventory_item_id' => $low->id, 'quantity' => 3]);

    $expiring = InventoryItem::factory()->create(['expiry_date' => '2026-09-10', 'reorder_threshold' => 0]);
    StockMovement::factory()->create(['inventory_item_id' => $expiring->id, 'quantity' => 40]);

    $archivedLow = InventoryItem::factory()->archived()->create(['reorder_threshold' => 10]);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('inventory.low_count', 1)
        ->where('inventory.expiring_count', 1));

    Carbon::setTestNow();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=test_dashboard_reports_low_and_expiring_counts`
Expected: FAIL — `Property [inventory] does not exist` (or a null access).

- [ ] **Step 3: Add the `inventory` prop to `DashboardController`**

In `app/Http/Controllers/Admin/DashboardController.php` add `use App\Models\InventoryItem;`, then before the `Inertia::render`:

```php
        $activeItems = InventoryItem::query()
            ->where('active', true)
            ->withSum('movements as on_hand', 'quantity')
            ->get();
```

and add to the render array:

```php
            'inventory' => [
                'low_count' => $activeItems
                    ->filter(fn (InventoryItem $item) => (int) $item->on_hand <= $item->reorder_threshold)
                    ->count(),
                'expiring_count' => $activeItems
                    ->filter(fn (InventoryItem $item) => $item->isExpiringSoon())
                    ->count(),
            ],
```

- [ ] **Step 4: Render the tile in `Dashboard.jsx`**

Change the signature to `export default function Dashboard({ dueForRecall, outstanding, inventory }) {` and add this `<Link>` after the "Outstanding balances" link:

```jsx
                <Link
                    href={route('inventory.index', { filter: 'low' })}
                    className="block rounded bg-white p-4 shadow hover:bg-gray-50"
                >
                    <h3 className="font-semibold mb-1">Inventory</h3>
                    {inventory.low_count === 0 && inventory.expiring_count === 0 ? (
                        <p className="text-sm text-gray-500">Stock levels healthy.</p>
                    ) : (
                        <p className="text-sm text-gray-600">
                            {inventory.low_count} item{inventory.low_count === 1 ? '' : 's'} low on stock
                            {' · '}
                            {inventory.expiring_count} expiring soon
                        </p>
                    )}
                </Link>
```

- [ ] **Step 5: Run the test and build**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InventoryItemTest` — Expected: PASS.
Run: `npm run build` — Expected: succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/DashboardController.php resources/js/Pages/Dashboard.jsx tests/Feature/InventoryItemTest.php
git commit -m "Add the inventory dashboard tile"
```

---

### Task 6: Navigation + `Inventory/Index.jsx`

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx` (a `NavLink` and a `ResponsiveNavLink`, after "Reports")
- Overwrite: `resources/js/Pages/Inventory/Index.jsx` — a 3-line `return null` stub exists from Task 2 (needed because the Task 2 tests assert `->component('Inventory/Index')`, and `inertia.testing.ensure_pages_exist` requires the file on disk). Replace it wholesale with the component below.

**Interfaces:**
- Consumes: `inventory.index` props (`items`, `filters`) from Task 2; `inventory.store` from Task 3; `inventory.show`.
- Produces: the `/inventory` list UI — filter buttons (All / Low stock / Expiring / Archived), a debounced name search, a "New item" modal (`POST inventory.store`), a table linking each row to `inventory.show`.

- [ ] **Step 1: Add the nav links**

In `resources/js/Layouts/AuthenticatedLayout.jsx`, after the desktop `NavLink` block for Reports (`route('reports.index')`):

```jsx
                                <NavLink
                                    href={route('inventory.index')}
                                    active={route().current('inventory.*')}
                                >
                                    Inventory
                                </NavLink>
```

and after the responsive `ResponsiveNavLink` block for Reports:

```jsx
                        <ResponsiveNavLink
                            href={route('inventory.index')}
                            active={route().current('inventory.*')}
                        >
                            Inventory
                        </ResponsiveNavLink>
```

- [ ] **Step 2: Create `resources/js/Pages/Inventory/Index.jsx`**

```jsx
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/Pages/Patients/format';

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'low', label: 'Low stock' },
    { key: 'expiring', label: 'Expiring' },
    { key: 'archived', label: 'Archived' },
];

const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
const UNITS = ['box', 'piece', 'pair', 'pack', 'cartridge', 'bottle', 'tube', 'roll', 'ml'];

const STATUS_BADGE = {
    ok: 'bg-green-100 text-green-800 border-green-300',
    low: 'bg-amber-100 text-amber-800 border-amber-300',
    out: 'bg-red-100 text-red-800 border-red-300',
};

function categoryLabel(category) {
    return category.replace('_', ' ');
}

function emptyMessage(filter) {
    switch (filter) {
        case 'low':
            return 'No items are low on stock.';
        case 'expiring':
            return 'Nothing is expiring in the next 30 days.';
        case 'archived':
            return 'No archived items.';
        default:
            return 'No items yet. Add your first item to start tracking stock.';
    }
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

export default function Index({ items, filters }) {
    const [showCreate, setShowCreate] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    function reload(next) {
        router.get(
            route('inventory.index'),
            { filter: next.filter ?? filters.filter, search: (next.search ?? search) || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch(value) {
        setSearch(value);
        reload({ search: value });
    }

    const form = useForm({
        name: '',
        category: 'consumable',
        unit: '',
        reorder_threshold: '',
        supplier: '',
        expiry_date: '',
        notes: '',
        opening_quantity: '',
    });

    function submitCreate(e) {
        e.preventDefault();
        form.post(route('inventory.store'), {
            onSuccess: () => {
                form.reset();
                setShowCreate(false);
            },
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inventory</h2>}>
            <Head title="Inventory" />

            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-2">
                        {FILTERS.map((f) => (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => reload({ filter: f.key })}
                                className={`rounded border px-3 py-1 text-sm ${
                                    filters.filter === f.key
                                        ? 'border-gray-900 bg-gray-900 text-white'
                                        : 'border-gray-300 text-gray-600'
                                }`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowCreate(true)}
                        className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                    >
                        + New item
                    </button>
                </div>

                <input
                    type="search"
                    placeholder="Search by name"
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    className="w-full max-w-xs rounded border px-3 py-2 text-sm"
                />

                <div className="overflow-x-auto rounded border bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="border-b text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Item</th>
                                <th className="px-4 py-2">Category</th>
                                <th className="px-4 py-2 text-right">On hand</th>
                                <th className="px-4 py-2 text-right">Reorder at</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Expiry</th>
                                <th className="px-4 py-2">Supplier</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.id} className="border-b last:border-0 hover:bg-gray-50">
                                    <td className="px-4 py-2">
                                        <Link href={route('inventory.show', item.id)} className="text-blue-600">
                                            {item.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 capitalize text-gray-600">{categoryLabel(item.category)}</td>
                                    <td className="px-4 py-2 text-right">
                                        {item.on_hand} {item.unit}
                                    </td>
                                    <td className="px-4 py-2 text-right text-gray-500">{item.reorder_threshold}</td>
                                    <td className="px-4 py-2">
                                        <span
                                            className={`inline-block rounded border px-2 py-0.5 text-xs uppercase ${STATUS_BADGE[item.stock_status]}`}
                                        >
                                            {item.stock_status}
                                        </span>
                                    </td>
                                    <td className={`px-4 py-2 ${item.is_expiring_soon ? 'text-amber-700' : 'text-gray-500'}`}>
                                        {item.expiry_date ? formatDate(item.expiry_date) : '—'}
                                    </td>
                                    <td className="px-4 py-2 text-gray-500">{item.supplier ?? '—'}</td>
                                </tr>
                            ))}
                            {items.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-6 text-center text-gray-500">
                                        {emptyMessage(filters.filter)}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {showCreate && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitCreate} className="my-8 w-full max-w-lg space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">New item</h3>

                        <Field label="Name" error={form.errors.name}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Category" error={form.errors.category}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)}
                                >
                                    {CATEGORIES.map((c) => (
                                        <option key={c} value={c}>
                                            {categoryLabel(c)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Unit" error={form.errors.unit}>
                                <input
                                    type="text"
                                    list="inventory-units"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.unit}
                                    onChange={(e) => form.setData('unit', e.target.value)}
                                />
                                <datalist id="inventory-units">
                                    {UNITS.map((u) => (
                                        <option key={u} value={u} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Reorder threshold" error={form.errors.reorder_threshold}>
                                <input
                                    type="number"
                                    min="0"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.reorder_threshold}
                                    onChange={(e) => form.setData('reorder_threshold', e.target.value)}
                                />
                            </Field>
                            <Field label="Opening quantity" error={form.errors.opening_quantity}>
                                <input
                                    type="number"
                                    min="0"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.opening_quantity}
                                    onChange={(e) => form.setData('opening_quantity', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="Supplier (optional)" error={form.errors.supplier}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.supplier}
                                onChange={(e) => form.setData('supplier', e.target.value)}
                            />
                        </Field>

                        <Field label="Expiry date (optional)" error={form.errors.expiry_date}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.expiry_date}
                                onChange={(e) => form.setData('expiry_date', e.target.value)}
                            />
                        </Field>

                        <Field label="Notes (optional)" error={form.errors.notes}>
                            <textarea
                                rows={2}
                                className="w-full rounded border px-3 py-2"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    form.clearErrors();
                                    setShowCreate(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Create
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

Note: `Field` is a small file-local helper — the `Invoices/*` pages repeat this markup inline, but the inventory forms have enough fields that a local helper keeps the file focused. It is intentionally not shared.

- [ ] **Step 3: Build and manually sanity-check**

Run: `npm run build` — Expected: succeeds, no JSX errors.
Then (optional, if a dev server is convenient): `"$HOME/.config/herd/bin/php.bat" artisan serve` + `npm run dev`, register/login, visit `/inventory`, add an item with an opening quantity, confirm it appears and links to its page.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.jsx resources/js/Pages/Inventory/Index.jsx
git commit -m "Add the inventory index page and nav link"
```

---

### Task 7: `Inventory/Show.jsx`

**Files:**
- Overwrite: `resources/js/Pages/Inventory/Show.jsx` — a 3-line `return null` stub exists from Task 2 (same reason as `Index.jsx`). Replace it wholesale with the component below.

**Interfaces:**
- Consumes: `inventory.show` `item` prop from Task 2; `inventory.update` (edit + archive/restore) from Task 3; `inventory-movements.store` from Task 4.
- Produces: the item page — on-hand headline, details panel with an edit modal, archive/restore, a "Record movement" modal (type-conditional `direction` / `unit_cost` fields, reason required for adjustments), a newest-first movement history table.

- [ ] **Step 1: Create `resources/js/Pages/Inventory/Show.jsx`**

```jsx
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatPeso } from '@/Pages/Patients/format';

const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
const UNITS = ['box', 'piece', 'pair', 'pack', 'cartridge', 'bottle', 'tube', 'roll', 'ml'];
const MOVEMENT_TYPES = ['received', 'consumed', 'adjustment', 'expired'];

const TYPE_BADGE = {
    received: 'bg-green-100 text-green-800',
    consumed: 'bg-gray-100 text-gray-700',
    adjustment: 'bg-blue-100 text-blue-800',
    expired: 'bg-red-100 text-red-800',
};

function categoryLabel(category) {
    return category.replace('_', ' ');
}

function signedQty(qty) {
    return qty > 0 ? `+${qty}` : `${qty}`;
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

function Dialog({ children, onClose }) {
    return (
        <div
            className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4"
            onClick={onClose}
        >
            <div className="my-8 w-full max-w-lg rounded bg-white p-6" onClick={(e) => e.stopPropagation()}>
                {children}
            </div>
        </div>
    );
}

export default function Show({ item }) {
    const [showEdit, setShowEdit] = useState(false);
    const [showMovement, setShowMovement] = useState(false);
    const [showArchive, setShowArchive] = useState(false);

    const editForm = useForm({
        name: item.name,
        category: item.category,
        unit: item.unit,
        reorder_threshold: String(item.reorder_threshold),
        supplier: item.supplier ?? '',
        expiry_date: item.expiry_date ?? '',
        notes: item.notes ?? '',
    });

    function submitEdit(e) {
        e.preventDefault();
        editForm.patch(route('inventory.update', item.id), {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    }

    const movementForm = useForm({
        type: 'received',
        quantity: '',
        direction: 'increase',
        unit_cost: '',
        occurred_on: '',
        reason: '',
    });

    function submitMovement(e) {
        e.preventDefault();
        movementForm.post(route('inventory-movements.store', item.id), {
            preserveScroll: true,
            onSuccess: () => {
                movementForm.reset();
                setShowMovement(false);
            },
        });
    }

    function setActive(active) {
        router.patch(
            route('inventory.update', item.id),
            { active },
            { preserveScroll: true, onSuccess: () => setShowArchive(false) },
        );
    }

    const isAdjustment = movementForm.data.type === 'adjustment';
    const isReceived = movementForm.data.type === 'received';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{item.name}</h2>}>
            <Head title={item.name} />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                {!item.active && (
                    <div className="rounded border border-gray-300 bg-gray-100 p-3 text-sm text-gray-600">
                        This item is archived.
                    </div>
                )}

                <div className="rounded border bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-3xl font-semibold">
                                {item.on_hand} <span className="text-lg text-gray-500">{item.unit}</span>
                            </p>
                            <p className="mt-1 text-sm capitalize text-gray-500">
                                {categoryLabel(item.category)} · reorder at {item.reorder_threshold}
                            </p>
                            {item.is_expiring_soon && item.expiry_date && (
                                <p className="mt-1 text-sm text-amber-700">Expiring {formatDate(item.expiry_date)}</p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowMovement(true)}
                            className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                        >
                            Record movement
                        </button>
                    </div>
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Details</h3>
                        <button type="button" onClick={() => setShowEdit(true)} className="text-blue-600">
                            Edit details
                        </button>
                    </div>
                    <dl className="grid grid-cols-2 gap-2">
                        <div>
                            <dt className="text-gray-500">Supplier</dt>
                            <dd>{item.supplier ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Expiry date</dt>
                            <dd>{item.expiry_date ? formatDate(item.expiry_date) : '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Reorder threshold</dt>
                            <dd>{item.reorder_threshold}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Created</dt>
                            <dd>
                                {formatDate(item.created_at)} by {item.creator_name}
                            </dd>
                        </div>
                    </dl>
                    {item.notes && <p className="mt-3 text-gray-600">Notes: {item.notes}</p>}
                    <div className="mt-3 border-t pt-3">
                        {item.active ? (
                            <button type="button" onClick={() => setShowArchive(true)} className="text-sm text-red-700">
                                Archive item
                            </button>
                        ) : (
                            <button type="button" onClick={() => setActive(true)} className="text-sm text-blue-600">
                                Restore item
                            </button>
                        )}
                    </div>
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <h3 className="mb-2 font-semibold">Movement history</h3>
                    {item.movements.length === 0 ? (
                        <p className="text-gray-500">No movements recorded.</p>
                    ) : (
                        <table className="w-full">
                            <thead className="text-left text-gray-500">
                                <tr>
                                    <th className="py-1">Date</th>
                                    <th className="py-1">Type</th>
                                    <th className="py-1 text-right">Qty</th>
                                    <th className="py-1 text-right">Unit cost</th>
                                    <th className="py-1">Reason</th>
                                    <th className="py-1">By</th>
                                </tr>
                            </thead>
                            <tbody>
                                {item.movements.map((movement) => (
                                    <tr key={movement.id} className="border-b last:border-0">
                                        <td className="py-2">{formatDate(movement.occurred_on)}</td>
                                        <td className="py-2">
                                            <span
                                                className={`inline-block rounded px-2 py-0.5 text-xs capitalize ${TYPE_BADGE[movement.type]}`}
                                            >
                                                {movement.type}
                                            </span>
                                        </td>
                                        <td
                                            className={`py-2 text-right ${movement.quantity < 0 ? 'text-red-700' : 'text-green-700'}`}
                                        >
                                            {signedQty(movement.quantity)}
                                        </td>
                                        <td className="py-2 text-right text-gray-500">
                                            {movement.unit_cost !== null ? formatPeso(movement.unit_cost) : '—'}
                                        </td>
                                        <td className="py-2 text-gray-500">{movement.reason ?? '—'}</td>
                                        <td className="py-2 text-gray-500">{movement.creator_name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {showEdit && (
                <Dialog
                    onClose={() => {
                        editForm.clearErrors();
                        setShowEdit(false);
                    }}
                >
                    <form onSubmit={submitEdit} className="space-y-4">
                        <h3 className="font-semibold">Edit details</h3>
                        <Field label="Name" error={editForm.errors.name}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Category" error={editForm.errors.category}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={editForm.data.category}
                                    onChange={(e) => editForm.setData('category', e.target.value)}
                                >
                                    {CATEGORIES.map((c) => (
                                        <option key={c} value={c}>
                                            {categoryLabel(c)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Unit" error={editForm.errors.unit}>
                                <input
                                    type="text"
                                    list="inventory-units"
                                    className="w-full rounded border px-3 py-2"
                                    value={editForm.data.unit}
                                    onChange={(e) => editForm.setData('unit', e.target.value)}
                                />
                                <datalist id="inventory-units">
                                    {UNITS.map((u) => (
                                        <option key={u} value={u} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>
                        <Field label="Reorder threshold" error={editForm.errors.reorder_threshold}>
                            <input
                                type="number"
                                min="0"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.reorder_threshold}
                                onChange={(e) => editForm.setData('reorder_threshold', e.target.value)}
                            />
                        </Field>
                        <Field label="Supplier" error={editForm.errors.supplier}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.supplier}
                                onChange={(e) => editForm.setData('supplier', e.target.value)}
                            />
                        </Field>
                        <Field label="Expiry date" error={editForm.errors.expiry_date}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.expiry_date}
                                onChange={(e) => editForm.setData('expiry_date', e.target.value)}
                            />
                        </Field>
                        <Field label="Notes" error={editForm.errors.notes}>
                            <textarea
                                rows={2}
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                            />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    editForm.clearErrors();
                                    setShowEdit(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={editForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Save
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}

            {showMovement && (
                <Dialog
                    onClose={() => {
                        movementForm.clearErrors();
                        setShowMovement(false);
                    }}
                >
                    <form onSubmit={submitMovement} className="space-y-4">
                        <h3 className="font-semibold">Record movement</h3>
                        <p className="text-sm text-gray-500">
                            On hand: {item.on_hand} {item.unit}
                        </p>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Type" error={movementForm.errors.type}>
                                <select
                                    className="w-full rounded border px-3 py-2 capitalize"
                                    value={movementForm.data.type}
                                    onChange={(e) => movementForm.setData('type', e.target.value)}
                                >
                                    {MOVEMENT_TYPES.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Quantity" error={movementForm.errors.quantity}>
                                <input
                                    type="number"
                                    min="1"
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.quantity}
                                    onChange={(e) => movementForm.setData('quantity', e.target.value)}
                                />
                            </Field>
                        </div>

                        {isAdjustment && (
                            <Field label="Direction" error={movementForm.errors.direction}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.direction}
                                    onChange={(e) => movementForm.setData('direction', e.target.value)}
                                >
                                    <option value="increase">Increase</option>
                                    <option value="decrease">Decrease</option>
                                </select>
                            </Field>
                        )}

                        {isReceived && (
                            <Field label="Unit cost (₱, optional)" error={movementForm.errors.unit_cost}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.unit_cost}
                                    onChange={(e) => movementForm.setData('unit_cost', e.target.value)}
                                />
                            </Field>
                        )}

                        <Field label="Date" error={movementForm.errors.occurred_on}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={movementForm.data.occurred_on}
                                onChange={(e) => movementForm.setData('occurred_on', e.target.value)}
                            />
                        </Field>

                        <Field
                            label={isAdjustment ? 'Reason (required for adjustments)' : 'Reason (optional)'}
                            error={movementForm.errors.reason}
                        >
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={movementForm.data.reason}
                                onChange={(e) => movementForm.setData('reason', e.target.value)}
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    movementForm.clearErrors();
                                    setShowMovement(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={movementForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Record
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}

            {showArchive && (
                <Dialog onClose={() => setShowArchive(false)}>
                    <div className="space-y-4">
                        <h3 className="font-semibold">Archive this item?</h3>
                        <p className="text-sm text-gray-600">
                            It drops out of the active list and the dashboard alerts. Its history is kept and you can
                            restore it later.
                        </p>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowArchive(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => setActive(false)}
                                className="rounded border border-red-300 px-4 py-2 text-sm text-red-700"
                            >
                                Archive
                            </button>
                        </div>
                    </div>
                </Dialog>
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Build and sanity-check**

Run: `npm run build` — Expected: succeeds.
If a dev server is up: open an item, record a `received` movement (with unit cost), a `consumed`, an `adjustment` (confirm the reason field is required), try to consume more than on-hand (confirm the "Only N …" error shows on the quantity field), archive then restore.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Inventory/Show.jsx
git commit -m "Add the inventory item detail page"
```

---

### Task 8: DemoSeeder fixtures, CLAUDE.md, full-suite gate

**Files:**
- Modify: `database/seeders/DemoSeeder.php`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: everything above.
- Produces: a seeded supply room (~12 items, two low, one expiring, one archived) and the updated project notes.

- [ ] **Step 1: Add inventory fixtures to `DemoSeeder`**

In `database/seeders/DemoSeeder.php` add the model imports at the top:

```php
use App\Models\InventoryItem;
use App\Models\StockMovement;
```

Append this block at the end of `run()` (after the final `Invoice::factory()->void()` block; it reuses the `$staff` user already created earlier in the method):

```php
        // --- Inventory fixtures: a stocked supply room so /inventory and
        //     its dashboard tile are populated on a fresh seed. Additive.
        $catalogue = [
            ['Nitrile Gloves (M)', 'ppe', 'box', 6],
            ['Composite Resin A2', 'consumable', 'syringe', 4],
            ['Lidocaine 2% Cartridges', 'medication', 'box', 3],
            ['Prophy Paste', 'consumable', 'tub', 5],
            ['Alginate Impression Material', 'lab_material', 'bag', 2],
            ['Assorted Diamond Burs', 'instrument', 'pack', 3],
            ['Cotton Rolls', 'consumable', 'box', 8],
            ['Surgical Face Masks', 'ppe', 'box', 6],
            ['Fluoride Varnish', 'medication', 'box', 2],
            ['Autoclave Pouches', 'consumable', 'box', 4],
            ['Disposable Saliva Ejectors', 'consumable', 'bag', 5],
            ['Patient Bibs', 'ppe', 'box', 4],
        ];
        $suppliers = ['Henry Schein', 'Patterson Dental', 'DentalKart', 'Benco Dental'];

        foreach ($catalogue as [$name, $category, $unit, $threshold]) {
            $item = InventoryItem::factory()->create([
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'reorder_threshold' => $threshold,
                'supplier' => $suppliers[array_rand($suppliers)],
                'created_by' => $staff->id,
            ]);

            StockMovement::factory()->create([
                'inventory_item_id' => $item->id,
                'type' => 'received',
                'quantity' => rand(3, 6) * 10,
                'unit_cost' => rand(20, 400),
                'occurred_on' => Carbon::now()->subDays(rand(30, 120))->toDateString(),
                'created_by' => $staff->id,
            ]);

            foreach (range(1, rand(1, 3)) as $ignored) {
                StockMovement::factory()->create([
                    'inventory_item_id' => $item->id,
                    'type' => 'consumed',
                    'quantity' => -rand(2, 8),
                    'unit_cost' => null,
                    'occurred_on' => Carbon::now()->subDays(rand(1, 25))->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        // Tune a few fixtures: two low on stock, one expiring, one archived.
        $inventory = InventoryItem::orderBy('id')->get();

        foreach ($inventory->take(2) as $item) {
            $current = (int) $item->movements()->sum('quantity');
            if ($current > 1) {
                StockMovement::factory()->create([
                    'inventory_item_id' => $item->id,
                    'type' => 'consumed',
                    'quantity' => -($current - 1),
                    'unit_cost' => null,
                    'occurred_on' => Carbon::now()->subDay()->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        $inventory->get(2)?->update(['expiry_date' => Carbon::now()->addDays(15)->toDateString()]);
        $inventory->get(3)?->update(['active' => false]);
```

- [ ] **Step 2: Run the seeder against a fresh DB**

Run: `"$HOME/.config/herd/bin/php.bat" artisan migrate:fresh --seed --seeder=Database\\Seeders\\DemoSeeder`
Expected: completes with no error. (Or `migrate:fresh` then `db:seed --class=DemoSeeder`.)
Spot-check: `"$HOME/.config/herd/bin/php.bat" artisan tinker --execute="echo App\Models\InventoryItem::count();"` → `12`.

- [ ] **Step 3: Update `CLAUDE.md` — "Shipped so far"**

In the `docs/PLATFORM_VISION.md` "Shipped so far" list, after the **Phase 7, sub-project 2** bullet, add:

```markdown
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
```

- [ ] **Step 4: Update `CLAUDE.md` — "Known gaps"**

Append to the "Known gaps" list:

```markdown
- Inventory on-hand is re-derived (`SUM(stock_movements.quantity)`) on
  every read — the `/inventory` index, the item page, and the
  dashboard tile — with no cached column. Same accepted O(n) pattern
  as invoice balances and `Patient::dueForRecall()`.
- `/inventory` loads every item (with a movement-sum subquery) and
  filters/searches in PHP — no pagination, same as `patients.index` /
  `invoices.index`.
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
```

- [ ] **Step 5: Full-suite gate**

Run: `npm run build` — Expected: succeeds.
Run: `"$HOME/.config/herd/bin/php.bat" artisan test` — Expected: the entire suite passes (all pre-existing tests plus `InventoryItemTest` and `StockMovementTest`).

- [ ] **Step 6: Commit**

```bash
git add database/seeders/DemoSeeder.php CLAUDE.md
git commit -m "Seed inventory demo data and document the module"
```

---

## Self-Review

**1. Spec coverage**

| Spec section | Task |
|---|---|
| `inventory_items` / `stock_movements` schema | Task 1 |
| Consts, casts, relations, derived methods (`onHand`/`isLow`/`isExpiringSoon`/`stockStatus`) | Task 1 |
| Factories + states | Task 1 |
| `index` — filters (all/low/expiring/archived), search, no pagination | Task 2 |
| `show` — item projection, movements newest-first | Task 2 |
| `store` — validation, opening-quantity adjustment, `created_by` server-side, redirect to show | Task 3 |
| `update` — partial, `active` archive/restore, threshold null→0 | Task 3 |
| No `destroy` | Task 3 (assertion) |
| `StockMovementController::store` — signed quantity, `required_if` direction/reason, overdraw guard under lock, `unit_cost` received-only, append-only | Task 4 |
| Dashboard `inventory` prop + tile | Task 5 |
| Nav link | Task 6 |
| `Inventory/Index.jsx` — filter tabs, search, new-item modal, table, empty states | Task 6 |
| `Inventory/Show.jsx` — headline, details+edit modal, archive/restore, movement modal (conditional fields), history table | Task 7 |
| In-page modals, no `window.confirm` | Tasks 6–7 |
| Formatters reused from `Patients/format.js` | Tasks 6–7 |
| DemoSeeder fixtures (12 items, 2 low, 1 expiring, 1 archived) | Task 8 |
| CLAUDE.md shipped bullet + Known gaps | Task 8 |
| Full-suite + build gate | Task 8 |

No gaps.

**2. Placeholder scan** — no TBD/TODO; every code step has real code; no "add validation" hand-waves (validation rules are spelled out); tests contain concrete assertions.

**3. Type consistency**
- `onHand(): int`, `stockStatus(): string`, `isExpiringSoon(int $days = 30): bool`, `isLow(): bool` — defined Task 1, used identically in Tasks 2 and 5.
- `withSum('movements as on_hand', 'quantity')` — aliased attribute `on_hand` read as `(int) $item->on_hand` in Tasks 2 and 5 consistently.
- Route param `{inventoryItem}` → `InventoryItem $inventoryItem` — Tasks 2, 3, 4.
- Prop shape `items[].{id,name,category,unit,on_hand,reorder_threshold,stock_status,supplier,expiry_date,is_expiring_soon,active}` — produced Task 2, consumed Task 6. `item.{...,notes,created_at,creator_name,movements[]}` — produced Task 2, consumed Task 7. `movements[].{id,type,quantity,unit_cost,reason,occurred_on,created_at,creator_name}` — consistent.
- `inventory` dashboard prop `{low_count,expiring_count}` — produced Task 5, consumed Task 5 (`Dashboard.jsx`).
- Movement form field names (`type`, `quantity`, `direction`, `unit_cost`, `occurred_on`, `reason`) match the Task 4 validation keys exactly.

Consistent throughout.
