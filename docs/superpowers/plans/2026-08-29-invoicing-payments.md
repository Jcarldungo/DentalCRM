# Invoicing & Payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-patient invoicing + payments subsystem — staff raise an invoice (free-text line items, optional link to a treatment-plan item, flat discount), issue it, record payments against it, and see amount due / paid / balance on the patient's Billing tab, a dedicated invoice page, a clinic-wide `/invoices` index, and a dashboard tile.

**Architecture:** Three tables (`invoices`, `invoice_items`, `payments`), three Eloquent models with money-math helper methods on `Invoice`, two controllers (`InvoiceController` = index/show/store/update, `PaymentController` = store only). Derived figures (subtotal, total, amount paid, balance, "paid") are computed from loaded relations, never stored. An explicit state machine governs `draft → issued → void`; "paid" is a derived display state, not a status. Payments are strictly append-only. Two new Inertia pages plus one new patient-tab component; additions to `PatientController::show`, `DashboardController`, `Patients/Show.jsx`, `Dashboard.jsx`, `AuthenticatedLayout.jsx`.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit 11, MariaDB (`dentalcrm_testing`).

**Spec:** `docs/superpowers/specs/2026-08-29-invoicing-payments-design.md`

## Global Constraints

- PHP via Herd: `"$HOME/.config/herd/bin/php.bat" artisan ...` for artisan/tests, `"$HOME/.config/herd/bin/composer.bat"` for composer. `npm` on PATH. Run every command from the repo root.
- Tests run against MariaDB `dentalcrm_testing` (already exists), not SQLite. A fresh worktree needs `npm run build` (Vite manifest) before `artisan test`, or ~32 page-render tests fail.
- No RBAC, no auth changes. Every authenticated user can create / edit / issue / void invoices and record payments. All routes sit in the existing `Route::middleware('auth')->group(...)` in `routes/web.php`.
- Staff controllers live in `App\Http\Controllers\Admin\`. Route names are unprefixed. Tests are flat in `tests/Feature/<Name>Test.php`.
- Money columns are `decimal(10,2)`, Philippine pesos (`₱`) — same as `treatment_plan_items.estimated_cost`.
- **Nothing is transmitted anywhere** — no mail, no PDF, no printable slip in this slice.
- **Payments are append-only** — no update route, no destroy route, no controller method for either, no UI to reach one.
- An invoice is mutable only while `status === 'draft'` (line items, discount, notes). Issuing freezes them. `patient_id` is fixed at creation. There is no invoice `destroy`.
- Invoice status set: `Invoice::STATUSES = ['draft', 'issued', 'void']`. Payment method set: `Payment::METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other']`. Both are `const` arrays used by validation and the frontend `<select>`s (same pattern as `TreatmentPlanItem::PRIORITIES`).
- Invoice number is derived, display-only: `INV-` + zero-padded id (`INV-000042`). No number column.
- "Paid" is never a stored status — it is `status === 'issued' && balance <= 0`.
- Overpayment is rejected: a payment must be `> 0` and `<= the invoice's current balance`.
- `discount_amount` is a flat peso figure, validated `>= 0` and `<= line-item subtotal`.
- `created_by` is set server-side from `$request->user()->id` by direct assignment — never `$fillable`, never trusted from the request (same pattern as `TreatmentPlanItem` / `Prescription`).
- The `Invoice` money helpers return `float` (via `round(..., 2)`), so those props serialize as JSON numbers. Assert them in tests with float literals (`900.0`, not `900`) — Inertia's `->where()` compares strictly. Payment/`decimal:2` model attributes read back as strings (`'400.00'`); assert those as strings.
- Clean-codebase rules: no `dd()` / `console.log` / `var_dump()`, no unused imports, no commented-out code.
- Commits carry NO `Co-Authored-By` trailer (matches repo history). Short imperative subjects.

---

## File Structure

**Create:**
- `database/migrations/2026_08_29_130000_create_invoices_table.php` — `invoices` schema
- `database/migrations/2026_08_29_131000_create_invoice_items_table.php` — `invoice_items` schema
- `database/migrations/2026_08_29_132000_create_payments_table.php` — `payments` schema
- `app/Models/Invoice.php` — model + `STATUSES` const + `number()` / `subtotal()` / `total()` / `amountPaid()` / `balance()` / `isPaid()` helpers + relations
- `app/Models/InvoiceItem.php` — model + relations
- `app/Models/Payment.php` — model + `METHODS` const + relations
- `database/factories/InvoiceFactory.php` — default `draft`; `issued()` / `void()` states
- `database/factories/InvoiceItemFactory.php`
- `database/factories/PaymentFactory.php`
- `app/Http/Controllers/Admin/InvoiceController.php` — `index` / `show` / `store` / `update`
- `app/Http/Controllers/Admin/PaymentController.php` — `store` only
- `resources/js/Pages/Invoices/Index.jsx` — clinic-wide list + status filter
- `resources/js/Pages/Invoices/Show.jsx` — one invoice: items, payments, balance, lifecycle actions
- `resources/js/Pages/Patients/BillingTab.jsx` — patient Billing tab body
- `tests/Feature/InvoiceTest.php` — schema, model math, store, show, update, index
- `tests/Feature/PaymentTest.php` — payment recording + append-only guarantees

**Modify:**
- `app/Models/Patient.php` — `invoices(): HasMany` relation
- `routes/web.php` — `use` imports + 5 routes in the `auth` group
- `app/Http/Controllers/Admin/PatientController.php` — `invoices` prop on `show()`
- `app/Http/Controllers/Admin/DashboardController.php` — `outstanding` prop on `index()`
- `resources/js/Pages/Patients/Show.jsx` — sixth "Billing" tab wired to `BillingTab`
- `resources/js/Pages/Dashboard.jsx` — outstanding-balances tile
- `resources/js/Layouts/AuthenticatedLayout.jsx` — "Billing" `<NavLink>` + `<ResponsiveNavLink>` after "Workspace"
- `CLAUDE.md` — "Shipped so far" bullet + "Known gaps" notes

---

## Task 1: Schema — migrations, models, factories, Patient relation

**Files:**
- Create: `database/migrations/2026_08_29_130000_create_invoices_table.php`
- Create: `database/migrations/2026_08_29_131000_create_invoice_items_table.php`
- Create: `database/migrations/2026_08_29_132000_create_payments_table.php`
- Create: `app/Models/Invoice.php`
- Create: `app/Models/InvoiceItem.php`
- Create: `app/Models/Payment.php`
- Create: `database/factories/InvoiceFactory.php`
- Create: `database/factories/InvoiceItemFactory.php`
- Create: `database/factories/PaymentFactory.php`
- Modify: `app/Models/Patient.php`
- Test: `tests/Feature/InvoiceTest.php` (new)

**Interfaces:**
- Consumes: existing `Patient`, `Provider`, `TreatmentPlanItem`, `User` models and their factories.
- Produces:
  - `Invoice` model — `$fillable = ['patient_id', 'discount_amount', 'notes']`; casts `discount_amount` → `decimal:2`, `issued_at`/`voided_at` → `datetime`; `const STATUSES = ['draft', 'issued', 'void']`.
    - `items(): HasMany` (ordered by `id`), `payments(): HasMany` (ordered by `paid_on` then `id`), `patient(): BelongsTo`, `creator(): BelongsTo(User::class, 'created_by')`.
    - `number(): string` → `'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT)`.
    - `subtotal(): float` → `round((float) $this->items->sum('amount'), 2)`.
    - `total(): float` → `round($this->subtotal() - (float) $this->discount_amount, 2)`.
    - `amountPaid(): float` → `round((float) $this->payments->sum('amount'), 2)`.
    - `balance(): float` → `round($this->total() - $this->amountPaid(), 2)`.
    - `isPaid(): bool` → `$this->status === 'issued' && $this->balance() <= 0.0`.
  - `InvoiceItem` model — `$fillable = ['invoice_id', 'treatment_plan_item_id', 'provider_id', 'description', 'amount']`; casts `amount` → `decimal:2`; `invoice(): BelongsTo`, `treatmentPlanItem(): BelongsTo`, `provider(): BelongsTo`.
  - `Payment` model — `$fillable = ['invoice_id', 'amount', 'method', 'paid_on', 'reference', 'note']`; casts `amount` → `decimal:2`, `paid_on` → `date:Y-m-d`; `const METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other']`; `invoice(): BelongsTo`, `creator(): BelongsTo(User::class, 'created_by')`.
  - `Patient::invoices(): HasMany` → `$this->hasMany(Invoice::class)->latest('created_at')->latest('id')`.
  - `InvoiceFactory` — default state `status = 'draft'`, `discount_amount = 0`, `patient_id` via `Patient::factory()`, `created_by` via `User::factory()`, `notes = null`; `->issued()` sets `status = 'issued'`, `issued_at = now()`; `->void()` sets `status = 'void'`, `voided_at = now()`.
  - `InvoiceItemFactory` — `invoice_id` via `Invoice::factory()`, `description` a short phrase, `amount` a realistic peso figure, `treatment_plan_item_id = null`, `provider_id = null`.
  - `PaymentFactory` — `invoice_id` via `Invoice::factory()->issued()`, `amount` a peso figure, `method = 'cash'`, `paid_on = now()->toDateString()`, `reference = null`, `note = null`, `created_by` via `User::factory()`.

- [ ] **Step 1: Write the `invoices` migration**

Create `database/migrations/2026_08_29_130000_create_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
```

- [ ] **Step 2: Write the `invoice_items` migration**

Create `database/migrations/2026_08_29_131000_create_invoice_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
```

- [ ] **Step 3: Write the `payments` migration**

Create `database/migrations/2026_08_29_132000_create_payments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method');
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/Invoice.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient invoice. Line items, discount, and notes are editable only
 * while status is 'draft'; issuing freezes them. Every money figure
 * below 'discount_amount' is DERIVED from the loaded items/payments —
 * nothing is stored. "Paid" is a derived display state (issued +
 * balance <= 0), not a status. See
 * docs/superpowers/specs/2026-08-29-invoicing-payments-design.md.
 */
class Invoice extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'issued', 'void'];

    protected $fillable = [
        'patient_id',
        'discount_amount',
        'notes',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Display-only invoice number, derived from the primary key. */
    public function number(): string
    {
        return 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function subtotal(): float
    {
        return round((float) $this->items->sum('amount'), 2);
    }

    public function total(): float
    {
        return round($this->subtotal() - (float) $this->discount_amount, 2);
    }

    public function amountPaid(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round($this->total() - $this->amountPaid(), 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'issued' && $this->balance() <= 0.0;
    }
}
```

Create `app/Models/InvoiceItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'treatment_plan_item_id',
        'provider_id',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function treatmentPlanItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanItem::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
```

Create `app/Models/Payment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment against an invoice. Append-only: there is no update or
 * destroy route, controller method, or UI. A mistaken payment is
 * corrected by a future refund concept, not by editing this row.
 */
class Payment extends Model
{
    use HasFactory;

    public const METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other'];

    protected $fillable = [
        'invoice_id',
        'amount',
        'method',
        'paid_on',
        'reference',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date:Y-m-d',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: Add the `Patient::invoices()` relation**

Modify `app/Models/Patient.php` — add after the `prescriptions()` method (line ~53):

```php
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('created_at')->latest('id');
    }
```

(`HasMany` is already imported in `Patient.php`.)

- [ ] **Step 6: Write the factories**

Create `database/factories/InvoiceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'status' => 'draft',
            'discount_amount' => 0,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => 'issued',
            'issued_at' => now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn () => [
            'status' => 'void',
            'voided_at' => now(),
        ]);
    }
}
```

Create `database/factories/InvoiceItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'treatment_plan_item_id' => null,
            'provider_id' => null,
            'description' => $this->faker->randomElement([
                'Root Canal Treatment',
                'Dental Filling',
                'Consultation fee',
                'Dental Crown',
                'X-ray',
            ]),
            'amount' => $this->faker->randomElement([500, 1500, 3000, 5000, 8000]),
        ];
    }
}
```

Create `database/factories/PaymentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'amount' => $this->faker->randomElement([500, 1000, 2000, 5000]),
            'method' => 'cash',
            'paid_on' => now()->toDateString(),
            'reference' => null,
            'note' => null,
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 7: Write the failing schema/model tests**

Create `tests/Feature/InvoiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_invoice_number_is_derived_and_zero_padded(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT), $invoice->number());
    }

    public function test_money_helpers_derive_subtotal_total_paid_and_balance(): void
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 200]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $invoice->load(['items', 'payments']);

        $this->assertSame(1500.0, $invoice->subtotal());
        $this->assertSame(1300.0, $invoice->total());
        $this->assertSame(300.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->balance());
        $this->assertFalse($invoice->isPaid());
    }

    public function test_is_paid_is_true_only_when_issued_and_balance_zero(): void
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        $invoice->load(['items', 'payments']);
        $this->assertTrue($invoice->isPaid());

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 0]);
        $draft->load(['items', 'payments']);
        $this->assertFalse($draft->isPaid());
    }

    public function test_deleting_a_patient_cascades_to_invoices_items_and_payments(): void
    {
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id]);
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id]);

        $patient->delete();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_a_provider_nulls_an_invoice_items_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $item = InvoiceItem::factory()->create(['provider_id' => $provider->id]);

        $provider->delete();

        $this->assertNull($item->fresh()->provider_id);
    }

    public function test_patient_invoices_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $older = Invoice::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);
        $newer = Invoice::factory()->create(['patient_id' => $patient->id, 'created_at' => now()]);

        $ordered = $patient->invoices;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_treatment_plan_item_link_is_nullable_and_belongs_to(): void
    {
        $tpi = TreatmentPlanItem::factory()->create();
        $item = InvoiceItem::factory()->create(['treatment_plan_item_id' => $tpi->id]);

        $this->assertSame($tpi->id, $item->treatmentPlanItem->id);
    }

    public function test_no_invoice_destroy_or_payment_write_routes_exist(): void
    {
        $this->assertFalse(Route::has('invoices.destroy'));
        $this->assertFalse(Route::has('invoice-payments.update'));
        $this->assertFalse(Route::has('invoice-payments.destroy'));
    }
}
```

- [ ] **Step 8: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: FAIL — `Class "App\Models\Invoice" not found` (until the models exist) or migration errors.

- [ ] **Step 9: Run the migrations and re-run the tests**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: PASS (8 tests). `RefreshDatabase` runs the new migrations automatically.

If `test_no_invoice_destroy_or_payment_write_routes_exist` is the only failure, check nothing accidentally registered those route names — they should not exist yet.

- [ ] **Step 10: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pre-existing tests pass + 8 new.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_29_13*_*.php app/Models/Invoice.php app/Models/InvoiceItem.php app/Models/Payment.php app/Models/Patient.php database/factories/InvoiceFactory.php database/factories/InvoiceItemFactory.php database/factories/PaymentFactory.php tests/Feature/InvoiceTest.php
git commit -m "Add invoices, invoice_items, payments schema and models"
```

---

## Task 2: `InvoiceController` store + show + routes

**Files:**
- Create: `app/Http/Controllers/Admin/InvoiceController.php` (with `store` + `show` only; `index` + `update` added in Tasks 5 & 3)
- Modify: `routes/web.php`
- Test: `tests/Feature/InvoiceTest.php` (created in Task 1)

**Interfaces:**
- Consumes: `Invoice` / `InvoiceItem` / `Payment` / `TreatmentPlanItem` models and helpers from Task 1.
- Produces:
  - Route `POST /invoices` → `Admin\InvoiceController@store`, name `invoices.store`.
  - Route `GET /invoices/{invoice}` → `Admin\InvoiceController@show`, name `invoices.show`.
  - `InvoiceController::store(Request $request): RedirectResponse` — validates `patient_id` (required, `exists:patients,id`), then `items` (required array, min 1), `items.*.description` (required string max 255), `items.*.amount` (required numeric min 0), `items.*.treatment_plan_item_id` (nullable, `Rule::exists('treatment_plan_items', 'id')->where('patient_id', <patient_id>)`), `discount_amount` (nullable numeric min 0), `notes` (nullable string). Rejects `discount_amount` > line-item subtotal (`ValidationException` on `discount_amount`). Creates the invoice with `status = 'draft'`, `created_by` by direct assignment; creates each item, copying `provider_id` from the linked TPI when linked. Redirects to `route('invoices.show', $invoice)`.
  - `InvoiceController::show(Invoice $invoice): Response` — eager-loads `items.treatmentPlanItem`, `items.provider`, `payments.creator`, `patient`, `creator`; renders `Invoices/Show` with props `invoice` (shape below) and `treatmentPlanItems` (`[{id, label, estimated_cost}]` — the patient's `planned`/`scheduled`/`in_progress`/`completed` items).
  - `invoice` prop shape: `{ id, number, status, patient: {id, full_name}, notes, discount_amount (float), subtotal, total, amount_paid, balance (all float), is_paid (bool), issued_at (ISO|null), voided_at (ISO|null), created_at (ISO), creator_name, items: [{id, description, amount (float), treatment_plan_item_id (int|null), treatment_plan_item_label (string|null), provider_name (string|null)}], payments: [{id, amount (float), method, paid_on (Y-m-d), reference (string|null), note (string|null), created_at (ISO), creator_name}] }`.
  - Private helpers other tasks reuse: `present(Invoice $invoice): array`, `linkableTreatmentItems(int $patientId): \Illuminate\Support\Collection`, `validatePayload(Request $request, int $patientId): array`, `syncItems(Invoice $invoice, array $items): void`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InvoiceTest.php` (inside the class):

```php
    public function test_guest_cannot_create_or_view_invoices(): void
    {
        $invoice = Invoice::factory()->create();

        $this->post(route('invoices.store'), [])->assertRedirect(route('login'));
        $this->get(route('invoices.show', $invoice))->assertRedirect(route('login'));
    }

    public function test_it_creates_a_draft_invoice_with_line_items(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'discount_amount' => 100,
            'notes' => 'First visit.',
            'items' => [
                ['description' => 'Consultation fee', 'amount' => 800],
                ['description' => 'X-ray', 'amount' => 1200],
            ],
        ]);

        $invoice = Invoice::first();
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($patient->id, $invoice->patient_id);
        $this->assertSame('100.00', $invoice->discount_amount);
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame(2, $invoice->items()->count());
        $this->assertNull($invoice->issued_at);
    }

    public function test_created_by_ignores_a_request_supplied_value(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'created_by' => $other->id,
            'status' => 'issued',
            'items' => [['description' => 'Cleaning', 'amount' => 1500]],
        ]);

        $invoice = Invoice::first();
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame('draft', $invoice->status);
    }

    public function test_a_line_can_link_to_a_treatment_plan_item_and_copies_its_provider(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $tpi = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
        ]);

        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [
                ['description' => 'Root canal', 'amount' => 8000, 'treatment_plan_item_id' => $tpi->id],
            ],
        ]);

        $item = InvoiceItem::first();
        $this->assertSame($tpi->id, $item->treatment_plan_item_id);
        $this->assertSame($provider->id, $item->provider_id);
    }

    public function test_store_validation_rejects_bad_payloads(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsTpi = TreatmentPlanItem::factory()->create();

        $this->post(route('invoices.store'), ['patient_id' => $patient->id, 'items' => []])
            ->assertSessionHasErrors('items');
        $this->post(route('invoices.store'), ['patient_id' => $patient->id])
            ->assertSessionHasErrors('items');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['amount' => 100]],
        ])->assertSessionHasErrors('items.0.description');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['description' => 'x', 'amount' => -5]],
        ])->assertSessionHasErrors('items.0.amount');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'items' => [['description' => 'x', 'amount' => 100, 'treatment_plan_item_id' => $otherPatientsTpi->id]],
        ])->assertSessionHasErrors('items.0.treatment_plan_item_id');
        $this->post(route('invoices.store'), [
            'patient_id' => $patient->id,
            'discount_amount' => 500,
            'items' => [['description' => 'x', 'amount' => 100]],
        ])->assertSessionHasErrors('discount_amount');

        $this->assertSame(0, Invoice::count());
    }

    public function test_show_returns_the_invoice_with_derived_figures(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 100]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 400]);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Invoices/Show')
            ->where('invoice.number', $invoice->number())
            ->where('invoice.subtotal', 1000.0)
            ->where('invoice.total', 900.0)
            ->where('invoice.amount_paid', 400.0)
            ->where('invoice.balance', 500.0)
            ->where('invoice.is_paid', false)
            ->has('invoice.items', 1)
            ->has('invoice.payments', 1)
        );
    }

    public function test_show_exposes_linkable_treatment_plan_items_for_the_patient(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $open = TreatmentPlanItem::factory()->create(['patient_id' => $patient->id, 'status' => 'planned']);
        TreatmentPlanItem::factory()->create(['patient_id' => $patient->id, 'status' => 'cancelled']);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertInertia(fn ($page) => $page
            ->has('treatmentPlanItems', 1)
            ->where('treatmentPlanItems.0.id', $open->id)
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: FAIL — `Route [invoices.store] not defined`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoices for a patient. store() always creates a 'draft'; show()
 * projects the invoice with every money figure derived from its loaded
 * items/payments. update() (Task 3) handles draft edits and the
 * draft -> issued -> void state machine. There is no destroy().
 */
class InvoiceController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items.treatmentPlanItem', 'items.provider', 'payments.creator', 'patient', 'creator']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $this->present($invoice),
            'treatmentPlanItems' => $this->linkableTreatmentItems($invoice->patient_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['patient_id' => ['required', 'exists:patients,id']]);
        $patientId = (int) $request->input('patient_id');

        $validated = $this->validatePayload($request, $patientId);

        $invoice = new Invoice([
            'patient_id' => $patientId,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);
        $invoice->status = 'draft';
        $invoice->created_by = $request->user()->id;
        $invoice->save();

        $this->syncItems($invoice, $validated['items']);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Validate the create/edit payload (everything except patient_id)
     * and reject a discount larger than the line-item subtotal.
     *
     * @return array{items: array<int, array<string, mixed>>, discount_amount?: string|null, notes?: string|null}
     */
    protected function validatePayload(Request $request, int $patientId): array
    {
        $validated = $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.treatment_plan_item_id' => [
                'nullable',
                Rule::exists('treatment_plan_items', 'id')->where('patient_id', $patientId),
            ],
        ]);

        $subtotal = collect($validated['items'])->sum(fn ($item) => (float) $item['amount']);
        if ((float) ($validated['discount_amount'] ?? 0) > $subtotal) {
            throw ValidationException::withMessages([
                'discount_amount' => 'The discount cannot exceed the line-item subtotal.',
            ]);
        }

        return $validated;
    }

    /** Replace an invoice's line items with the given set. */
    protected function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $item) {
            $tpiId = $item['treatment_plan_item_id'] ?? null;

            $invoice->items()->create([
                'treatment_plan_item_id' => $tpiId,
                'provider_id' => $tpiId
                    ? TreatmentPlanItem::whereKey($tpiId)->value('provider_id')
                    : null,
                'description' => $item['description'],
                'amount' => $item['amount'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number(),
            'status' => $invoice->status,
            'patient' => [
                'id' => $invoice->patient->id,
                'full_name' => $invoice->patient->full_name,
            ],
            'notes' => $invoice->notes,
            'discount_amount' => (float) $invoice->discount_amount,
            'subtotal' => $invoice->subtotal(),
            'total' => $invoice->total(),
            'amount_paid' => $invoice->amountPaid(),
            'balance' => $invoice->balance(),
            'is_paid' => $invoice->isPaid(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'created_at' => $invoice->created_at->toIso8601String(),
            'creator_name' => $invoice->creator->name,
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'amount' => (float) $item->amount,
                'treatment_plan_item_id' => $item->treatment_plan_item_id,
                'treatment_plan_item_label' => $item->treatmentPlanItem
                    ? $this->treatmentLabel($item->treatmentPlanItem)
                    : null,
                'provider_name' => $item->provider?->name,
            ])->values(),
            'payments' => $invoice->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'paid_on' => $payment->paid_on->toDateString(),
                'reference' => $payment->reference,
                'note' => $payment->note,
                'created_at' => $payment->created_at->toIso8601String(),
                'creator_name' => $payment->creator->name,
            ])->values(),
        ];
    }

    /**
     * The patient's treatment-plan items worth putting on a bill:
     * planned / scheduled / in_progress / completed. This status list is
     * duplicated in BillingTab.jsx — see CLAUDE.md "Known gaps".
     */
    protected function linkableTreatmentItems(int $patientId): Collection
    {
        return TreatmentPlanItem::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ['planned', 'scheduled', 'in_progress', 'completed'])
            ->orderBy('id')
            ->get()
            ->map(fn (TreatmentPlanItem $item) => [
                'id' => $item->id,
                'label' => $this->treatmentLabel($item),
                'estimated_cost' => (float) $item->estimated_cost,
            ]);
    }

    protected function treatmentLabel(TreatmentPlanItem $item): string
    {
        return $item->treatment
            .($item->tooth_number ? ' · tooth '.$item->tooth_number : '');
    }
}
```

- [ ] **Step 4: Add the routes**

Modify `routes/web.php`:

Add the imports alphabetically among the `Admin` controller imports — `InvoiceController` after `DentalRecordController` (line 5), and `PaymentController` after `PatientController` (line 6):

```php
use App\Http\Controllers\Admin\InvoiceController;
```
```php
use App\Http\Controllers\Admin\PaymentController;
```

Inside `Route::middleware('auth')->group(...)`, immediately after the `/workspace` route (line ~89):

```php
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoice-payments.store');
```

(The `index`, `update`, and `PaymentController@store` targets are implemented in Tasks 5, 3, and 4 — registering all five routes now keeps `routes/web.php` untouched by later tasks. Until then, hitting `/invoices`, `PATCH /invoices/{invoice}`, or the payments route errors, but no test in this task exercises them.)

- [ ] **Step 5: Create a placeholder so the routes resolve**

The `index` and `update` methods do not exist yet. To keep `php artisan route:list` and the test bootstrap from throwing, add stub methods to `InvoiceController` that will be replaced in Tasks 3 and 5:

```php
    public function index(Request $request): Response
    {
        abort(404);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        abort(404);
    }
```

Place them just above `show()`. Tasks 3 and 5 replace the bodies.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: PASS (16 tests — 8 from Task 1 + 8 new).

- [ ] **Step 7: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/InvoiceController.php routes/web.php tests/Feature/InvoiceTest.php
git commit -m "Add InvoiceController store and show with derived totals"
```

---

## Task 3: `InvoiceController::update` — draft edits + state machine

**Files:**
- Modify: `app/Http/Controllers/Admin/InvoiceController.php`
- Test: `tests/Feature/InvoiceTest.php`

**Interfaces:**
- Consumes: `validatePayload()`, `syncItems()` from Task 2; `Invoice::STATUSES`, `Invoice::items()`, `Invoice::payments()`.
- Produces:
  - `InvoiceController::update(Request $request, Invoice $invoice): RedirectResponse` — two modes:
    - **Transition mode** (request has a `status` key): validates `status` in `Invoice::STATUSES`; allows only `draft→issued`, `draft→void`, `issued→void`; `draft→issued` requires `>= 1` line item; `issued→void` requires `0` payments; stamps `issued_at` / `voided_at`; all via direct assignment. Any other move throws `ValidationException` on `status`.
    - **Edit mode** (no `status` key): `abort_unless($invoice->status === 'draft', 403)`; validates via `validatePayload($request, $invoice->patient_id)`; updates `discount_amount` + `notes`; replaces items via `syncItems()`.
  - Both modes `return back()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InvoiceTest.php`:

```php
    public function test_guest_cannot_update_an_invoice(): void
    {
        $invoice = Invoice::factory()->create();

        $this->patch(route('invoices.update', $invoice), ['status' => 'issued'])
            ->assertRedirect(route('login'));
    }

    public function test_editing_a_draft_replaces_items_and_updates_discount_and_notes(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create(['discount_amount' => 0, 'notes' => null]);
        $stale = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->patch(route('invoices.update', $invoice), [
            'discount_amount' => 50,
            'notes' => 'Adjusted.',
            'items' => [
                ['description' => 'New line A', 'amount' => 400],
                ['description' => 'New line B', 'amount' => 600],
            ],
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('50.00', $invoice->discount_amount);
        $this->assertSame('Adjusted.', $invoice->notes);
        $this->assertDatabaseMissing('invoice_items', ['id' => $stale->id]);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_editing_is_rejected_once_issued_or_void(): void
    {
        $this->actingUser();
        $issued = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $issued->id, 'amount' => 100]);

        $this->patch(route('invoices.update', $issued), [
            'items' => [['description' => 'Sneaky', 'amount' => 999]],
        ])->assertForbidden();

        $this->assertSame(1, $issued->items()->count());
        $this->assertSame('100.00', $issued->items()->first()->amount);
    }

    public function test_issuing_requires_at_least_one_line_item(): void
    {
        $this->actingUser();
        $empty = Invoice::factory()->create();

        $this->patch(route('invoices.update', $empty), ['status' => 'issued'])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $empty->fresh()->status);

        $ok = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $ok->id, 'amount' => 500]);
        $this->patch(route('invoices.update', $ok), ['status' => 'issued'])->assertRedirect();
        $ok->refresh();
        $this->assertSame('issued', $ok->status);
        $this->assertNotNull($ok->issued_at);
    }

    public function test_a_draft_voids_freely_and_stamps_voided_at(): void
    {
        $this->actingUser();
        $invoice = Invoice::factory()->create();

        $this->patch(route('invoices.update', $invoice), ['status' => 'void'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('void', $invoice->status);
        $this->assertNotNull($invoice->voided_at);
    }

    public function test_an_issued_invoice_voids_only_without_payments(): void
    {
        $this->actingUser();
        $withPayment = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $withPayment->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $withPayment->id, 'amount' => 100]);

        $this->patch(route('invoices.update', $withPayment), ['status' => 'void'])
            ->assertSessionHasErrors('status');
        $this->assertSame('issued', $withPayment->fresh()->status);

        $clean = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $clean->id, 'amount' => 1000]);
        $this->patch(route('invoices.update', $clean), ['status' => 'void'])->assertRedirect();
        $this->assertSame('void', $clean->fresh()->status);
    }

    public function test_illegal_transitions_are_rejected(): void
    {
        $this->actingUser();

        $issued = Invoice::factory()->issued()->create();
        InvoiceItem::factory()->create(['invoice_id' => $issued->id, 'amount' => 100]);
        $this->patch(route('invoices.update', $issued), ['status' => 'draft'])
            ->assertSessionHasErrors('status');
        $this->assertSame('issued', $issued->fresh()->status);

        $void = Invoice::factory()->void()->create();
        $this->patch(route('invoices.update', $void), ['status' => 'issued'])
            ->assertSessionHasErrors('status');
        $this->patch(route('invoices.update', $void), ['status' => 'draft'])
            ->assertSessionHasErrors('status');
        $this->assertSame('void', $void->fresh()->status);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: FAIL — `update()` currently `abort(404)`, so these get 404 instead of the expected redirect/validation errors.

- [ ] **Step 3: Implement `update()`**

In `app/Http/Controllers/Admin/InvoiceController.php`, replace the `update()` stub with:

```php
    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($request->has('status')) {
            return $this->transition($request, $invoice);
        }

        abort_unless($invoice->status === 'draft', 403);

        $validated = $this->validatePayload($request, $invoice->patient_id);

        $invoice->update([
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);
        $this->syncItems($invoice, $validated['items']);

        return back();
    }

    /**
     * The draft -> issued -> void state machine. The only legal moves
     * are draft->issued, draft->void, and issued->void (the last only
     * while the invoice has no payments). Everything else is a
     * validation error on 'status'.
     */
    protected function transition(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Invoice::STATUSES)],
        ]);

        $from = $invoice->status;
        $to = $validated['status'];

        $legal = ($from === 'draft' && $to === 'issued')
            || ($from === 'draft' && $to === 'void')
            || ($from === 'issued' && $to === 'void');

        if (! $legal) {
            throw ValidationException::withMessages([
                'status' => "An invoice cannot move from {$from} to {$to}.",
            ]);
        }

        if ($to === 'issued' && $invoice->items()->count() < 1) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one line item before issuing this invoice.',
            ]);
        }

        if ($from === 'issued' && $to === 'void' && $invoice->payments()->count() > 0) {
            throw ValidationException::withMessages([
                'status' => 'An invoice with recorded payments cannot be voided.',
            ]);
        }

        $invoice->status = $to;
        if ($to === 'issued') {
            $invoice->issued_at = now();
        }
        if ($to === 'void') {
            $invoice->voided_at = now();
        }
        $invoice->save();

        return back();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: PASS (23 tests).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/InvoiceController.php tests/Feature/InvoiceTest.php
git commit -m "Add invoice draft edits and the draft/issued/void state machine"
```

---

## Task 4: `PaymentController::store`

**Files:**
- Create: `app/Http/Controllers/Admin/PaymentController.php`
- Test: `tests/Feature/PaymentTest.php` (new)

**Interfaces:**
- Consumes: `Invoice` (helpers `balance()`, `load()`), `Payment::METHODS`, route `invoice-payments.store` (registered in Task 2).
- Produces:
  - `PaymentController::store(Request $request, Invoice $invoice): RedirectResponse` — `abort_unless($invoice->status === 'issued', 403)`; validates `amount` (required numeric `gt:0`), `method` (required, `Rule::in(Payment::METHODS)`), `paid_on` (nullable date), `reference` (nullable string max 255), `note` (nullable string max 255); loads `items` + `payments`, computes `balance()`, rejects `amount` > balance (`ValidationException` on `amount`); creates the `payments` row with `paid_on` defaulting to today and `created_by` by direct assignment; `return back()`.
  - No other methods.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** An issued invoice with a single $amount line item and no payments. */
    protected function issuedInvoice(float $amount = 1000): Invoice
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);

        return $invoice;
    }

    public function test_guest_cannot_record_a_payment(): void
    {
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'cash'])
            ->assertRedirect(route('login'));
    }

    public function test_it_records_a_payment_against_an_issued_invoice(): void
    {
        $user = $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 400,
            'method' => 'card',
            'reference' => 'AUTH123',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('400.00', $payment->amount);
        $this->assertSame('card', $payment->method);
        $this->assertSame('AUTH123', $payment->reference);
        $this->assertSame(now()->toDateString(), $payment->paid_on->toDateString());
        $this->assertSame($user->id, $payment->created_by);
    }

    public function test_paid_on_is_respected_when_supplied(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), [
            'amount' => 100,
            'method' => 'cash',
            'paid_on' => '2026-08-20',
        ]);

        $this->assertSame('2026-08-20', Payment::first()->paid_on->toDateString());
    }

    public function test_payments_are_only_allowed_on_issued_invoices(): void
    {
        $this->actingUser();

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 500]);
        $this->post(route('invoice-payments.store', $draft), ['amount' => 100, 'method' => 'cash'])
            ->assertForbidden();

        $void = Invoice::factory()->void()->create();
        $this->post(route('invoice-payments.store', $void), ['amount' => 100, 'method' => 'cash'])
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_amount_must_be_positive_and_within_the_balance(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 0, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoice-payments.store', $invoice), ['amount' => -50, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoice-payments.store', $invoice), ['amount' => 1000.01, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_equal_to_the_balance_is_accepted_and_closes_the_invoice(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 1000, 'method' => 'cash'])
            ->assertRedirect();

        $invoice->load(['items', 'payments']);
        $this->assertSame(0.0, $invoice->balance());
        $this->assertTrue($invoice->isPaid());
    }

    public function test_partial_payments_accumulate(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice(1000);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 300, 'method' => 'cash']);
        $this->post(route('invoice-payments.store', $invoice), ['amount' => 400, 'method' => 'cash']);

        $invoice->load(['items', 'payments']);
        $this->assertSame(300.0, $invoice->balance());
        $this->assertFalse($invoice->isPaid());

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 300, 'method' => 'cash']);
        $invoice->load(['items', 'payments']);
        $this->assertTrue($invoice->fresh()->load(['items', 'payments'])->isPaid());
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->actingUser();
        $invoice = $this->issuedInvoice();

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'bitcoin'])
            ->assertSessionHasErrors('method');
        $this->assertSame(0, Payment::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PaymentTest`
Expected: FAIL — `Target class [App\Http\Controllers\Admin\PaymentController] does not exist`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/PaymentController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording a payment against an issued invoice, and nothing else.
 * Payments are append-only — there is deliberately no update() or
 * destroy() here, and no matching route. Overpayment is rejected: the
 * amount is capped at the invoice's current balance.
 */
class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->status === 'issued', 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice->load(['items', 'payments']);
        $balance = $invoice->balance();

        if ((float) $validated['amount'] > $balance) {
            throw ValidationException::withMessages([
                'amount' => 'Payment of '.number_format((float) $validated['amount'], 2)
                    .' exceeds the outstanding balance of '.number_format($balance, 2).'.',
            ]);
        }

        $payment = $invoice->payments()->make([
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'paid_on' => $validated['paid_on'] ?? now()->toDateString(),
            'reference' => $validated['reference'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);
        $payment->created_by = $request->user()->id;
        $payment->save();

        return back();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PaymentTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PaymentController.php tests/Feature/PaymentTest.php
git commit -m "Add PaymentController store with overpayment and status guards"
```

---

## Task 5: `InvoiceController::index` — clinic-wide list + status filter

**Files:**
- Modify: `app/Http/Controllers/Admin/InvoiceController.php`
- Test: `tests/Feature/InvoiceTest.php`

**Interfaces:**
- Consumes: `Invoice` model + helpers, route `invoices.index` (registered in Task 2).
- Produces:
  - `InvoiceController::index(Request $request): Response` — validates `status` (nullable, `Rule::in(['all', 'draft', 'outstanding', 'paid', 'void'])`); loads all invoices newest-first with `items`, `payments`, `patient:id,first_name,last_name`; filters in memory by the `status` param (`outstanding` = issued + `balance() > 0`; `paid` = issued + `balance() <= 0`); renders `Invoices/Index` with props `invoices` (`[{id, number, patient_id, patient_name, status, total, amount_paid, balance, is_paid, created_at}]`) and `filters` (`{status}`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InvoiceTest.php`:

```php
    public function test_guest_cannot_view_the_invoices_index(): void
    {
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_all_invoices_newest_first_by_default(): void
    {
        $this->actingUser();
        $older = Invoice::factory()->create(['created_at' => now()->subDay()]);
        $newer = Invoice::factory()->create(['created_at' => now()]);

        $response = $this->get(route('invoices.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Invoices/Index')
            ->where('filters.status', 'all')
            ->has('invoices', 2)
            ->where('invoices.0.id', $newer->id)
            ->where('invoices.1.id', $older->id)
        );
    }

    public function test_index_status_filters_bucket_correctly(): void
    {
        $this->actingUser();

        $draft = Invoice::factory()->create();

        $outstanding = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 400]);

        $paid = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);

        $void = Invoice::factory()->void()->create();

        $this->get(route('invoices.index', ['status' => 'draft']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $draft->id));
        $this->get(route('invoices.index', ['status' => 'outstanding']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $outstanding->id));
        $this->get(route('invoices.index', ['status' => 'paid']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $paid->id));
        $this->get(route('invoices.index', ['status' => 'void']))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $void->id));
        $this->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->has('invoices', 4));
    }

    public function test_index_rejects_an_unknown_status_filter(): void
    {
        $this->actingUser();

        $this->get(route('invoices.index', ['status' => 'nonsense']))
            ->assertSessionHasErrors('status');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: FAIL — `index()` currently `abort(404)`.

- [ ] **Step 3: Implement `index()`**

In `app/Http/Controllers/Admin/InvoiceController.php`, replace the `index()` stub with:

```php
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'draft', 'outstanding', 'paid', 'void'])],
        ]);
        $filter = $validated['status'] ?? 'all';

        $invoices = Invoice::query()
            ->latest('created_at')
            ->latest('id')
            ->with(['items', 'payments', 'patient:id,first_name,last_name'])
            ->get()
            ->filter(fn (Invoice $invoice) => match ($filter) {
                'draft' => $invoice->status === 'draft',
                'outstanding' => $invoice->status === 'issued' && $invoice->balance() > 0,
                'paid' => $invoice->status === 'issued' && $invoice->balance() <= 0,
                'void' => $invoice->status === 'void',
                default => true,
            })
            ->values()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number(),
                'patient_id' => $invoice->patient_id,
                'patient_name' => $invoice->patient->full_name,
                'status' => $invoice->status,
                'total' => $invoice->total(),
                'amount_paid' => $invoice->amountPaid(),
                'balance' => $invoice->balance(),
                'is_paid' => $invoice->isPaid(),
                'created_at' => $invoice->created_at->toIso8601String(),
            ]);

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => ['status' => $filter],
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: PASS (28 tests).

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/InvoiceController.php tests/Feature/InvoiceTest.php
git commit -m "Add the clinic-wide invoices index with status filters"
```

---

## Task 6: Read-side props — patient Billing prop + dashboard outstanding tile

**Files:**
- Modify: `app/Http/Controllers/Admin/PatientController.php`
- Modify: `app/Http/Controllers/Admin/DashboardController.php`
- Test: `tests/Feature/InvoiceTest.php`

**Interfaces:**
- Consumes: `Invoice` model + helpers, `Patient::invoices()`.
- Produces:
  - `PatientController::show()` gains an `invoices` prop: `[{id, number, status, total, amount_paid, balance, is_paid, created_at}]` for the patient, newest first (relation order).
  - `DashboardController::index()` gains an `outstanding` prop: `{total (float), count (int)}` — summed `balance()` over `status = 'issued'` invoices with a positive balance.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/InvoiceTest.php`:

```php
    public function test_patient_show_lists_only_that_patients_invoices_with_derived_figures(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();

        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id, 'discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250]);
        Invoice::factory()->create(['patient_id' => $other->id]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('invoices', 1)
            ->where('invoices.0.id', $invoice->id)
            ->where('invoices.0.number', $invoice->number())
            ->where('invoices.0.total', 1000.0)
            ->where('invoices.0.amount_paid', 250.0)
            ->where('invoices.0.balance', 750.0)
            ->where('invoices.0.is_paid', false)
        );
    }

    public function test_dashboard_outstanding_totals_only_issued_invoices_with_a_balance(): void
    {
        $this->actingUser();

        $outstanding = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $outstanding->id, 'amount' => 200]);

        $paid = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $paid->id, 'amount' => 500]);

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 9999]);

        $void = Invoice::factory()->void()->create();
        InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 9999]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('outstanding.total', 800.0)
            ->where('outstanding.count', 1)
        );
    }
```

Note: `route('dashboard')` needs a verified user. `User::factory()` creates users with `email_verified_at` set by default in this app's factory, so `actingUser()` satisfies the `verified` middleware. If this test 403s or redirects, add `->create(['email_verified_at' => now()])` in a local user setup — but check `UserFactory` first; it almost certainly already sets it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: FAIL — `invoices` / `outstanding` props missing.

- [ ] **Step 3: Add the `invoices` prop to `PatientController::show()`**

Modify `app/Http/Controllers/Admin/PatientController.php`:

Add `use App\Models\Invoice;` with the other model imports (after `use App\Models\DentalRecord;`).

In `show()`, add this prop to the `Inertia::render('Patients/Show', [...])` array — put it right after the `'prescriptions' => ...` block and before `'providers' => ...`:

```php
            'invoices' => $patient->invoices()
                ->with(['items', 'payments'])
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number(),
                    'status' => $invoice->status,
                    'total' => $invoice->total(),
                    'amount_paid' => $invoice->amountPaid(),
                    'balance' => $invoice->balance(),
                    'is_paid' => $invoice->isPaid(),
                    'created_at' => $invoice->created_at->toIso8601String(),
                ]),
```

- [ ] **Step 4: Add the `outstanding` prop to `DashboardController::index()`**

Modify `app/Http/Controllers/Admin/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Patient;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $dueForRecall = Patient::dueForRecall()->map(fn (Patient $patient) => [
            'id' => $patient->id,
            'full_name' => $patient->full_name,
            'due_date' => $patient->recall_due_date->toDateString(),
            'last_cleaning_at' => $patient->recall_last_cleaning_at->toDateString(),
        ])->values();

        $outstandingInvoices = Invoice::where('status', 'issued')
            ->with(['items', 'payments'])
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->balance() > 0);

        return Inertia::render('Dashboard', [
            'dueForRecall' => $dueForRecall,
            'outstanding' => [
                'total' => round($outstandingInvoices->sum(fn (Invoice $invoice) => $invoice->balance()), 2),
                'count' => $outstandingInvoices->count(),
            ],
        ]);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=InvoiceTest`
Expected: PASS (30 tests).

- [ ] **Step 6: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass. (Pre-existing `DashboardTest` / patient-show tests still pass — the new props are additive.)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/PatientController.php app/Http/Controllers/Admin/DashboardController.php tests/Feature/InvoiceTest.php
git commit -m "Expose patient invoices and dashboard outstanding-balance totals"
```

---

## Task 7: Frontend — `BillingTab.jsx` + the patient "Billing" tab

**Files:**
- Create: `resources/js/Pages/Patients/BillingTab.jsx`
- Modify: `resources/js/Pages/Patients/Show.jsx`

**Interfaces:**
- Consumes: props from Task 6's `Patients/Show` render — `invoices` (row shape from Task 6 Produces), the existing `treatmentPlanItems` prop, `patient`. Routes `invoices.store`, `invoices.show`.
- Produces: `BillingTab.jsx` default-exports `BillingTab({ patient, invoices, treatmentPlanItems })`. `Show.jsx` renders a sixth "Billing" tab.

No PHPUnit test (React view code; the data contract is covered by Tasks 2/6). Verify with `npm run build` + the full `artisan test` suite (the page-render tests must still pass).

- [ ] **Step 1: Create `BillingTab.jsx`**

Create `resources/js/Pages/Patients/BillingTab.jsx`:

```jsx
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { formatDate, formatPeso } from './format';

// planned / scheduled / in_progress / completed — a treatment worth putting
// on a bill. Mirrors InvoiceController::linkableTreatmentItems() (see
// CLAUDE.md "Known gaps").
const BILLABLE_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress', 'completed'];

const STATUS_BADGE = {
    draft: 'bg-gray-100 text-gray-700 border-gray-300',
    issued: 'bg-blue-100 text-blue-800 border-blue-300',
    paid: 'bg-green-100 text-green-800 border-green-300',
    void: 'bg-gray-200 text-gray-500 border-gray-300 line-through',
};

function statusLabel(invoice) {
    if (invoice.is_paid) return 'paid';
    return invoice.status;
}

const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

export default function BillingTab({ patient, invoices, treatmentPlanItems }) {
    const [showNewModal, setShowNewModal] = useState(false);

    const billable = treatmentPlanItems.filter((tpi) =>
        BILLABLE_TREATMENT_STATUSES.includes(tpi.status),
    );

    const form = useForm({
        patient_id: patient.id,
        items: [{ ...BLANK_LINE }],
        discount_amount: '',
        notes: '',
    });

    function openNew() {
        form.reset();
        form.clearErrors();
        form.setData({
            patient_id: patient.id,
            items: [{ ...BLANK_LINE }],
            discount_amount: '',
            notes: '',
        });
        setShowNewModal(true);
    }

    function setLine(index, patch) {
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    }

    function linkTreatment(index, tpiId) {
        if (!tpiId) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }
        const tpi = billable.find((t) => String(t.id) === String(tpiId));
        setLine(index, {
            treatment_plan_item_id: tpiId,
            description: tpi ? tpi.treatment : form.data.items[index].description,
            amount: tpi ? tpi.estimated_cost : form.data.items[index].amount,
        });
    }

    function addLine() {
        form.setData('items', [...form.data.items, { ...BLANK_LINE }]);
    }

    function removeLine(index) {
        if (form.data.items.length === 1) return;
        form.setData('items', form.data.items.filter((_, i) => i !== index));
    }

    function submit(e) {
        e.preventDefault();
        form.post(route('invoices.store'), {
            onSuccess: () => setShowNewModal(false),
        });
    }

    const totalBilled = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.total, 0);
    const totalPaid = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.amount_paid, 0);
    const totalOutstanding = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.balance, 0);

    return (
        <div>
            <div className="mb-4 flex flex-wrap gap-6 rounded bg-white p-4 text-sm shadow">
                <div>
                    <div className="text-gray-500">Billed</div>
                    <div className="font-medium">{formatPeso(totalBilled)}</div>
                </div>
                <div>
                    <div className="text-gray-500">Paid</div>
                    <div className="font-medium">{formatPeso(totalPaid)}</div>
                </div>
                <div>
                    <div className="text-gray-500">Outstanding</div>
                    <div className="font-medium">{formatPeso(totalOutstanding)}</div>
                </div>
            </div>

            <button
                type="button"
                onClick={openNew}
                className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
            >
                + New Invoice
            </button>

            <div className="space-y-2">
                {invoices.map((invoice) => (
                    <Link
                        key={invoice.id}
                        href={route('invoices.show', invoice.id)}
                        className="block rounded border bg-white p-4 text-sm shadow-sm hover:bg-gray-50"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium">{invoice.number}</span>
                            <span
                                className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[statusLabel(invoice)]}`}
                            >
                                {statusLabel(invoice)}
                            </span>
                        </div>
                        <div className="mt-1 flex flex-wrap gap-4 text-gray-500">
                            <span>{formatDate(invoice.created_at)}</span>
                            <span>Total {formatPeso(invoice.total)}</span>
                            <span>Balance {formatPeso(invoice.balance)}</span>
                        </div>
                    </Link>
                ))}
                {invoices.length === 0 && (
                    <div className="rounded border bg-white p-4 text-sm text-gray-500 shadow-sm">
                        No invoices for this patient yet.
                    </div>
                )}
            </div>

            {showNewModal && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submit} className="my-8 w-full max-w-2xl space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">New invoice</h3>

                        <div className="space-y-3">
                            {form.data.items.map((line, index) => (
                                <div key={index} className="rounded border p-3">
                                    <div className="mb-2">
                                        <label className="mb-1 block text-sm">Link to treatment (optional)</label>
                                        <select
                                            className="w-full rounded border px-3 py-2"
                                            value={line.treatment_plan_item_id}
                                            onChange={(e) => linkTreatment(index, e.target.value)}
                                        >
                                            <option value="">Not linked</option>
                                            {billable.map((tpi) => (
                                                <option key={tpi.id} value={tpi.id}>
                                                    {tpi.treatment}
                                                    {tpi.tooth_number ? ` · tooth ${tpi.tooth_number}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="col-span-2">
                                            <label className="mb-1 block text-sm">Description</label>
                                            <input
                                                type="text"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.description}
                                                onChange={(e) => setLine(index, { description: e.target.value })}
                                            />
                                            {form.errors[`items.${index}.description`] && (
                                                <p className="text-sm text-red-600">
                                                    {form.errors[`items.${index}.description`]}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm">Amount (₱)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.amount}
                                                onChange={(e) => setLine(index, { amount: e.target.value })}
                                            />
                                            {form.errors[`items.${index}.amount`] && (
                                                <p className="text-sm text-red-600">
                                                    {form.errors[`items.${index}.amount`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    {form.data.items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => removeLine(index)}
                                            className="mt-2 text-sm text-red-600"
                                        >
                                            Remove line
                                        </button>
                                    )}
                                </div>
                            ))}
                            {form.errors.items && <p className="text-sm text-red-600">{form.errors.items}</p>}
                            <button type="button" onClick={addLine} className="text-sm text-blue-600">
                                + Add line
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1 block text-sm">Discount (₱)</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.discount_amount}
                                    onChange={(e) => form.setData('discount_amount', e.target.value)}
                                />
                                {form.errors.discount_amount && (
                                    <p className="text-sm text-red-600">{form.errors.discount_amount}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Notes</label>
                            <textarea
                                className="w-full rounded border px-3 py-2"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { form.clearErrors(); setShowNewModal(false); }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Create draft
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Wire the "Billing" tab into `Show.jsx`**

Modify `resources/js/Pages/Patients/Show.jsx`:

Add the import after the `PrescriptionsTab` import (line 5):

```jsx
import BillingTab from './BillingTab';
```

Add `invoices` to the destructured props (line 84):

```jsx
export default function Show({ patient, dentalRecords, toothConditions, treatmentPlanItems, prescriptions, invoices, providers, appointments }) {
```

Add the tab button immediately after the "Prescriptions" `<button>` (closes at line 273), inside the tab bar `<div>`:

```jsx
                    <button
                        type="button"
                        onClick={() => setTab('billing')}
                        className={`pb-2 text-sm font-medium ${tab === 'billing' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Billing
                    </button>
```

Add the tab body immediately after the `{tab === 'prescriptions' && ( ... )}` block (closes at line 450):

```jsx
                {tab === 'billing' && (
                    <BillingTab
                        patient={patient}
                        invoices={invoices}
                        treatmentPlanItems={treatmentPlanItems}
                    />
                )}
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds with no "X is not defined" / unresolved-import errors.

- [ ] **Step 4: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass — the existing `Patients/Show` render tests still pass with the new tab and prop.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Patients/BillingTab.jsx resources/js/Pages/Patients/Show.jsx
git commit -m "Add the patient Billing tab with invoice creation"
```

---

## Task 8: Frontend — invoice pages, dashboard tile, nav link, docs

**Files:**
- Create: `resources/js/Pages/Invoices/Index.jsx`
- Create: `resources/js/Pages/Invoices/Show.jsx`
- Modify: `resources/js/Pages/Dashboard.jsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: `Invoices/Index` props (`invoices`, `filters`) from Task 5; `Invoices/Show` props (`invoice`, `treatmentPlanItems`) from Task 2; `Dashboard` `outstanding` prop from Task 6. Routes `invoices.index`, `invoices.show`, `invoices.update`, `invoice-payments.store`, `patients.show`.
- Produces: two page components, a dashboard tile, two nav links, updated docs. No new backend interface.

No PHPUnit test. Verify with `npm run build` + full `artisan test`.

- [ ] **Step 1: Create `Invoices/Index.jsx`**

Create `resources/js/Pages/Invoices/Index.jsx`:

```jsx
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatPeso } from '@/Pages/Patients/format';

const FILTERS = ['all', 'draft', 'outstanding', 'paid', 'void'];

const STATUS_BADGE = {
    draft: 'bg-gray-100 text-gray-700 border-gray-300',
    issued: 'bg-blue-100 text-blue-800 border-blue-300',
    paid: 'bg-green-100 text-green-800 border-green-300',
    void: 'bg-gray-200 text-gray-500 border-gray-300 line-through',
};

function statusLabel(invoice) {
    return invoice.is_paid ? 'paid' : invoice.status;
}

export default function Index({ invoices, filters }) {
    function setFilter(status) {
        router.get(
            route('invoices.index'),
            status === 'all' ? {} : { status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Billing</h2>}>
            <Head title="Billing" />

            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-4 flex flex-wrap gap-2">
                    {FILTERS.map((status) => (
                        <button
                            key={status}
                            type="button"
                            onClick={() => setFilter(status)}
                            className={`rounded border px-3 py-1 text-sm capitalize ${
                                filters.status === status
                                    ? 'border-gray-900 bg-gray-900 text-white'
                                    : 'border-gray-300 text-gray-600'
                            }`}
                        >
                            {status}
                        </button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded border bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="border-b text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Invoice</th>
                                <th className="px-4 py-2">Patient</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2 text-right">Total</th>
                                <th className="px-4 py-2 text-right">Paid</th>
                                <th className="px-4 py-2 text-right">Balance</th>
                                <th className="px-4 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr key={invoice.id} className="border-b last:border-0 hover:bg-gray-50">
                                    <td className="px-4 py-2">
                                        <Link href={route('invoices.show', invoice.id)} className="text-blue-600">
                                            {invoice.number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link href={route('patients.show', invoice.patient_id)} className="text-blue-600">
                                            {invoice.patient_name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 text-gray-500">{formatDate(invoice.created_at)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.total)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.amount_paid)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.balance)}</td>
                                    <td className="px-4 py-2">
                                        <span
                                            className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[statusLabel(invoice)]}`}
                                        >
                                            {statusLabel(invoice)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {invoices.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-6 text-center text-gray-500">
                                        No {filters.status === 'all' ? '' : filters.status} invoices.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Create `Invoices/Show.jsx`**

Create `resources/js/Pages/Invoices/Show.jsx`:

```jsx
import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime, formatPeso } from '@/Pages/Patients/format';

const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other'];

const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

function methodLabel(method) {
    return method.replace('_', ' ');
}

export default function Show({ invoice, treatmentPlanItems }) {
    const [showEdit, setShowEdit] = useState(false);
    const [showPayment, setShowPayment] = useState(false);

    const isDraft = invoice.status === 'draft';
    const isIssued = invoice.status === 'issued';
    const isVoid = invoice.status === 'void';

    const transition = useForm({ status: '' });

    function move(status) {
        transition.transform(() => ({ status }));
        transition.patch(route('invoices.update', invoice.id), { preserveScroll: true });
    }

    const editForm = useForm({
        items: invoice.items.length
            ? invoice.items.map((item) => ({
                  description: item.description,
                  amount: item.amount,
                  treatment_plan_item_id: item.treatment_plan_item_id ?? '',
              }))
            : [{ ...BLANK_LINE }],
        discount_amount: invoice.discount_amount || '',
        notes: invoice.notes ?? '',
    });

    function setLine(index, patch) {
        editForm.setData(
            'items',
            editForm.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    }

    function linkTreatment(index, tpiId) {
        if (!tpiId) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }
        const tpi = treatmentPlanItems.find((t) => String(t.id) === String(tpiId));
        setLine(index, {
            treatment_plan_item_id: tpiId,
            description: tpi ? tpi.label : editForm.data.items[index].description,
            amount: tpi ? tpi.estimated_cost : editForm.data.items[index].amount,
        });
    }

    function submitEdit(e) {
        e.preventDefault();
        editForm.patch(route('invoices.update', invoice.id), {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    }

    const paymentForm = useForm({
        amount: invoice.balance > 0 ? invoice.balance : '',
        method: 'cash',
        paid_on: '',
        reference: '',
        note: '',
    });

    function submitPayment(e) {
        e.preventDefault();
        paymentForm.post(route('invoice-payments.store', invoice.id), {
            preserveScroll: true,
            onSuccess: () => {
                paymentForm.reset();
                setShowPayment(false);
            },
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{invoice.number}</h2>}>
            <Head title={invoice.number} />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Link href={route('patients.show', invoice.patient.id)} className="font-medium text-blue-600">
                            {invoice.patient.full_name}
                        </Link>
                        <span className="text-gray-500">
                            {invoice.is_paid ? 'paid' : invoice.status}
                        </span>
                    </div>
                    <div className="mt-1 text-gray-500">
                        Created {formatDate(invoice.created_at)} by {invoice.creator_name}
                        {invoice.issued_at && ` · issued ${formatDateTime(invoice.issued_at)}`}
                        {invoice.voided_at && ` · voided ${formatDateTime(invoice.voided_at)}`}
                    </div>
                </div>

                {isVoid && (
                    <div className="rounded border border-gray-300 bg-gray-100 p-3 text-sm text-gray-600">
                        This invoice has been voided.
                    </div>
                )}
                {invoice.is_paid && (
                    <div className="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
                        Paid in full.
                    </div>
                )}

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Line items</h3>
                        {isDraft && (
                            <button type="button" onClick={() => setShowEdit(true)} className="text-sm text-blue-600">
                                Edit
                            </button>
                        )}
                    </div>
                    <table className="w-full">
                        <tbody>
                            {invoice.items.map((item) => (
                                <tr key={item.id} className="border-b last:border-0">
                                    <td className="py-2">
                                        {item.description}
                                        {item.treatment_plan_item_label && (
                                            <span className="text-gray-400"> · {item.treatment_plan_item_label}</span>
                                        )}
                                        {item.provider_name && (
                                            <span className="text-gray-400"> · {item.provider_name}</span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">{formatPeso(item.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-3 space-y-1 border-t pt-3 text-right">
                        <div>Subtotal: {formatPeso(invoice.subtotal)}</div>
                        {invoice.discount_amount > 0 && <div>Discount: −{formatPeso(invoice.discount_amount)}</div>}
                        <div className="font-semibold">Total: {formatPeso(invoice.total)}</div>
                    </div>
                    {invoice.notes && <p className="mt-3 text-gray-600">Notes: {invoice.notes}</p>}
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Payments</h3>
                        {isIssued && invoice.balance > 0 && (
                            <button type="button" onClick={() => setShowPayment(true)} className="text-sm text-blue-600">
                                Record payment
                            </button>
                        )}
                    </div>
                    {invoice.payments.length === 0 ? (
                        <p className="text-gray-500">No payments recorded.</p>
                    ) : (
                        <table className="w-full">
                            <tbody>
                                {invoice.payments.map((payment) => (
                                    <tr key={payment.id} className="border-b last:border-0">
                                        <td className="py-2">{formatDate(payment.paid_on)}</td>
                                        <td className="py-2 capitalize">{methodLabel(payment.method)}</td>
                                        <td className="py-2 text-gray-400">{payment.reference}</td>
                                        <td className="py-2 text-right">{formatPeso(payment.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    <div className="mt-3 space-y-1 border-t pt-3 text-right">
                        <div>Paid: {formatPeso(invoice.amount_paid)}</div>
                        <div className="font-semibold">Balance due: {formatPeso(invoice.balance)}</div>
                    </div>
                </div>

                {(isDraft || (isIssued && invoice.payments.length === 0)) && (
                    <div className="flex flex-wrap gap-2">
                        {isDraft && (
                            <button
                                type="button"
                                onClick={() => move('issued')}
                                disabled={transition.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Issue invoice
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => move('void')}
                            disabled={transition.processing}
                            className="rounded border border-red-300 px-4 py-2 text-sm text-red-700"
                        >
                            Void
                        </button>
                        {transition.errors.status && (
                            <p className="w-full text-sm text-red-600">{transition.errors.status}</p>
                        )}
                    </div>
                )}
            </div>

            {showEdit && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitEdit} className="my-8 w-full max-w-2xl space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">Edit invoice</h3>

                        <div className="space-y-3">
                            {editForm.data.items.map((line, index) => (
                                <div key={index} className="rounded border p-3">
                                    <div className="mb-2">
                                        <label className="mb-1 block text-sm">Link to treatment (optional)</label>
                                        <select
                                            className="w-full rounded border px-3 py-2"
                                            value={line.treatment_plan_item_id}
                                            onChange={(e) => linkTreatment(index, e.target.value)}
                                        >
                                            <option value="">Not linked</option>
                                            {treatmentPlanItems.map((tpi) => (
                                                <option key={tpi.id} value={tpi.id}>{tpi.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="col-span-2">
                                            <label className="mb-1 block text-sm">Description</label>
                                            <input
                                                type="text"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.description}
                                                onChange={(e) => setLine(index, { description: e.target.value })}
                                            />
                                            {editForm.errors[`items.${index}.description`] && (
                                                <p className="text-sm text-red-600">
                                                    {editForm.errors[`items.${index}.description`]}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm">Amount (₱)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.amount}
                                                onChange={(e) => setLine(index, { amount: e.target.value })}
                                            />
                                            {editForm.errors[`items.${index}.amount`] && (
                                                <p className="text-sm text-red-600">
                                                    {editForm.errors[`items.${index}.amount`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    {editForm.data.items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                editForm.setData(
                                                    'items',
                                                    editForm.data.items.filter((_, i) => i !== index),
                                                )
                                            }
                                            className="mt-2 text-sm text-red-600"
                                        >
                                            Remove line
                                        </button>
                                    )}
                                </div>
                            ))}
                            {editForm.errors.items && <p className="text-sm text-red-600">{editForm.errors.items}</p>}
                            <button
                                type="button"
                                onClick={() => editForm.setData('items', [...editForm.data.items, { ...BLANK_LINE }])}
                                className="text-sm text-blue-600"
                            >
                                + Add line
                            </button>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Discount (₱)</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.discount_amount}
                                onChange={(e) => editForm.setData('discount_amount', e.target.value)}
                            />
                            {editForm.errors.discount_amount && (
                                <p className="text-sm text-red-600">{editForm.errors.discount_amount}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Notes</label>
                            <textarea
                                className="w-full rounded border px-3 py-2"
                                rows={2}
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { editForm.clearErrors(); setShowEdit(false); }}
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
                </div>
            )}

            {showPayment && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitPayment} className="my-8 w-full max-w-md space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">Record payment</h3>
                        <p className="text-sm text-gray-500">Balance due: {formatPeso(invoice.balance)}</p>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1 block text-sm">Amount (₱)</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={paymentForm.data.amount}
                                    onChange={(e) => paymentForm.setData('amount', e.target.value)}
                                />
                                {paymentForm.errors.amount && (
                                    <p className="text-sm text-red-600">{paymentForm.errors.amount}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-1 block text-sm">Method</label>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={paymentForm.data.method}
                                    onChange={(e) => paymentForm.setData('method', e.target.value)}
                                >
                                    {PAYMENT_METHODS.map((method) => (
                                        <option key={method} value={method}>{methodLabel(method)}</option>
                                    ))}
                                </select>
                                {paymentForm.errors.method && (
                                    <p className="text-sm text-red-600">{paymentForm.errors.method}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Date paid</label>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.paid_on}
                                onChange={(e) => paymentForm.setData('paid_on', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Reference (optional)</label>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.reference}
                                onChange={(e) => paymentForm.setData('reference', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Note (optional)</label>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.note}
                                onChange={(e) => paymentForm.setData('note', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { paymentForm.clearErrors(); setShowPayment(false); }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={paymentForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Record
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Add the dashboard tile**

Modify `resources/js/Pages/Dashboard.jsx`:

```jsx
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Dashboard({ dueForRecall, outstanding }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Dashboard</h2>}>
            <Head title="Dashboard" />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <Link
                    href={route('invoices.index', { status: 'outstanding' })}
                    className="block rounded bg-white p-4 shadow hover:bg-gray-50"
                >
                    <h3 className="font-semibold mb-1">Outstanding balances</h3>
                    {outstanding.count === 0 ? (
                        <p className="text-sm text-gray-500">No outstanding balances.</p>
                    ) : (
                        <p className="text-sm text-gray-600">
                            ₱{outstanding.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            {' '}across {outstanding.count} invoice{outstanding.count === 1 ? '' : 's'}
                        </p>
                    )}
                </Link>

                <div className="bg-white shadow rounded p-4">
                    <h3 className="font-semibold mb-3">Due for recall</h3>

                    {dueForRecall.length === 0 && (
                        <p className="text-sm text-gray-500">No one is currently overdue for a cleaning.</p>
                    )}

                    <ul className="divide-y">
                        {dueForRecall.map((patient) => (
                            <li key={patient.id} className="py-2 flex justify-between text-sm">
                                <span>{patient.full_name}</span>
                                <span className="text-gray-500">
                                    Last cleaning {patient.last_cleaning_at} — due {patient.due_date}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 4: Add the nav links**

Modify `resources/js/Layouts/AuthenticatedLayout.jsx`:

In the desktop nav, immediately after the `workspace.index` `<NavLink>` (closes at line 62) and before the `inquiries.index` `<NavLink>`:

```jsx
                                <NavLink
                                    href={route('invoices.index')}
                                    active={route().current('invoices.*')}
                                >
                                    Billing
                                </NavLink>
```

In the responsive nav, immediately after the `workspace.index` `<ResponsiveNavLink>` (closes at line 202) and before the `inquiries.index` `<ResponsiveNavLink>`:

```jsx
                        <ResponsiveNavLink
                            href={route('invoices.index')}
                            active={route().current('invoices.*')}
                        >
                            Billing
                        </ResponsiveNavLink>
```

- [ ] **Step 5: Update `CLAUDE.md`**

Modify `CLAUDE.md`:

**(a)** Under "Planning workflow" → "Shipped so far", after the "Phase 6, sub-project 5" bullet, add:

```markdown
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
```

**(b)** Under "Known gaps", append:

```markdown
- Invoice numbers are derived from the primary key (`INV-` + padded
  id) — not gapless, and they shift if rows are ever hard-deleted. A
  real clinic needing statutory numbering would want a dedicated
  counter.
- `/invoices` loads every invoice (with items + payments) and filters
  in PHP — no pagination or search, same as `patients.index`. The
  money helpers (`balance()` etc.) also re-derive on every read
  (index, patient tab, dashboard tile, invoice page); no cached
  column. Fine at demo scale.
- The "billable treatment-plan status" set
  (`planned`/`scheduled`/`in_progress`/`completed`) is duplicated in
  `InvoiceController::linkableTreatmentItems()` and `BillingTab.jsx` —
  same docblock-sync situation as the appointment/treatment status
  sets already noted.
```

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: succeeds, no unresolved imports or "X is not defined".

- [ ] **Step 7: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass (pre-existing + `InvoiceTest` (30) + `PaymentTest` (9)). The `Dashboard` render test still passes with the new `outstanding` prop and `Link` import.

- [ ] **Step 8: Dev smoke (optional, for a human)**

`"$HOME/.config/herd/bin/php.bat" artisan serve` + `npm run dev`:
1. `/patients/{id}` → "Billing" tab → "+ New Invoice", add two lines (link one to a treatment), create → lands on `/invoices/INV-…`.
2. On the invoice page: Edit (draft) adjusts lines; "Issue invoice" flips status and hides Edit.
3. "Record payment" (issued) → partial amount → balance drops; a payment covering the balance shows "Paid in full".
4. Try to void after a payment → error message; void a clean issued invoice → "voided" banner.
5. `/invoices` → filter tabs bucket correctly; rows link to invoice + patient.
6. Dashboard → "Outstanding balances" tile shows the total and links to the filtered index.
7. "Billing" nav link present (desktop + mobile), highlights on `/invoices` and `/invoices/{id}`.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Invoices/Index.jsx resources/js/Pages/Invoices/Show.jsx resources/js/Pages/Dashboard.jsx resources/js/Layouts/AuthenticatedLayout.jsx CLAUDE.md
git commit -m "Add invoice pages, billing nav link, and outstanding dashboard tile"
```

---

## Self-Review

**1. Spec coverage:**

| Spec section | Task |
|---|---|
| `invoices` / `invoice_items` / `payments` schema, casts, consts, relations | Task 1 |
| `Patient::invoices()` newest-first | Task 1 |
| Derived figures never stored — helper methods on `Invoice` | Task 1 (methods + tests), used everywhere |
| Invoice number derived `INV-` + padded id | Task 1 (`number()` + test) |
| `POST /invoices` → draft, items, optional TPI link + provider copy, discount ≤ subtotal, `created_by` server-set | Task 2 |
| `GET /invoices/{invoice}` — full projection incl. `is_paid`, items, payments, linkable TPIs | Task 2 |
| `PATCH /invoices/{invoice}` edit mode (draft only, full item replace, discount/notes) | Task 3 |
| `PATCH` transition mode — state machine (`draft→issued` needs items; `issued→void` needs no payments; illegal moves rejected); `issued_at`/`voided_at` stamps | Task 3 |
| `POST /invoices/{invoice}/payments` — issued-only, method validation, `amount > 0`, `amount ≤ balance`, `paid_on` default, `created_by` server-set, append-only | Task 4 |
| No `invoices.destroy` / `invoice-payments.update` / `.destroy` routes | Task 1 (`test_no_..._routes_exist`) + Task 4 |
| `GET /invoices` index — status filter (`all`/`draft`/`outstanding`/`paid`/`void`), newest first, no pagination | Task 5 |
| Void invoice excluded from `outstanding` / dashboard total | Task 5 test + Task 6 test |
| `PatientController::show` `invoices` prop (patient-scoped, derived figures) | Task 6 |
| `DashboardController` `outstanding` = {total, count} over issued + positive balance | Task 6 |
| Patient "Billing" tab — summary, invoice list, new-invoice modal w/ repeatable lines + TPI pre-fill + discount + notes | Task 7 |
| `Invoices/Show` — header, line items + subtotal/discount/total, payments + paid/balance, Edit (draft), Record payment (issued+balance), Issue/Void actions by state, void/paid banners, in-page modals (no `window.confirm`) | Task 8 |
| `Invoices/Index` — filter tabs, table, links to invoice + patient, per-filter empty state | Task 8 |
| Dashboard tile linking to `?status=outstanding` | Task 8 |
| `Billing` nav link (desktop + responsive) after "Workspace" | Task 8 |
| `formatPeso`/`formatDate` reused from `Patients/format.js` | Tasks 7 & 8 (imports) |
| CLAUDE.md shipped-so-far bullet + Known gaps (derived number, unpaginated index, duplicated status set) | Task 8 step 5 |
| Out-of-scope (no mail/PDF, no refunds/deposits/tax/reporting, no appointment link, subsystems independent) | Honoured — no task builds any of these |

**2. Placeholder scan:** No "TBD" / "handle edge cases" / "similar to Task N". Every code step has literal code. Task 2 step 5 adds explicit `abort(404)` stubs (not placeholders — real guards) that Tasks 3 & 5 replace with full bodies shown in those tasks. Dev-smoke in Task 8 is explicitly optional-for-a-human; binding verification is `npm run build` + full `artisan test`.

**3. Type consistency:**
- `Invoice` helper names — `number()`, `subtotal()`, `total()`, `amountPaid()`, `balance()`, `isPaid()` — defined in Task 1, called identically in Tasks 2 (`present`, `linkableTreatmentItems`), 3 (`transition` uses `items()`/`payments()` query builders, not helpers), 5 (`index`), 6 (`show` prop, dashboard). ✓
- `invoice` prop keys from Task 2 `present()` (`number`, `status`, `patient.id`, `patient.full_name`, `discount_amount`, `subtotal`, `total`, `amount_paid`, `balance`, `is_paid`, `issued_at`, `voided_at`, `created_at`, `creator_name`, `items[].{id,description,amount,treatment_plan_item_id,treatment_plan_item_label,provider_name}`, `payments[].{id,amount,method,paid_on,reference,note,created_at,creator_name}`) — all consumed with matching names in Task 8 `Invoices/Show.jsx`. ✓
- Index row keys from Task 5 (`id`, `number`, `patient_id`, `patient_name`, `status`, `total`, `amount_paid`, `balance`, `is_paid`, `created_at`) — all read in Task 8 `Invoices/Index.jsx`. ✓
- Patient-tab row keys from Task 6 (`id`, `number`, `status`, `total`, `amount_paid`, `balance`, `is_paid`, `created_at`) — all read in Task 7 `BillingTab.jsx`. ✓
- `treatmentPlanItems` for `Invoices/Show` is `[{id, label, estimated_cost}]` (Task 2 `linkableTreatmentItems`); Task 8 `linkTreatment` reads `tpi.label` / `tpi.estimated_cost`. ✓ In `BillingTab.jsx` (Task 7) the prop is the **existing** `Patients/Show` `treatmentPlanItems` shape (`{id, treatment, tooth_number, status, estimated_cost, ...}`) — Task 7 filters on `.status` and reads `.treatment` / `.tooth_number` / `.estimated_cost`, which that prop already carries (per `PatientController::show` map in the current code). ✓
- Route names — `invoices.index` / `invoices.store` / `invoices.show` / `invoices.update` / `invoice-payments.store` — identical across Task 2 route registration, all controller `redirect()->route(...)` calls, every test, and all frontend `route(...)` calls. ✓
- `Invoice::STATUSES` = `['draft','issued','void']` and `Payment::METHODS` = `['cash','card','bank_transfer','check','other']` — defined Task 1, echoed in `Invoices/Show.jsx` `PAYMENT_METHODS` (Task 8) and the badge maps. ✓
- `transition.transform(() => ({ status }))` in `Invoices/Show.jsx` sends only `status` → controller's `$request->has('status')` routes to transition mode. The edit form posts `items`/`discount_amount`/`notes` with no `status` key → edit mode. ✓
