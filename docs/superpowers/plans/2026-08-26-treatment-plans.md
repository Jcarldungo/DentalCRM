# Treatment Plans Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each patient a "Treatment Plan" tab on their detail page (`/patients/{patient}`) showing a flat, mutable working list of proposed treatments, each trackable through `planned → scheduled → in_progress → completed/cancelled`.

**Architecture:** One new table (`treatment_plan_items`) and model, with `patient_id` cascading on delete and `provider_id`/`appointment_id` nullable and null-on-delete — the same shape as `dental_records`/`tooth_conditions`. Unlike those two append-only models, this one is mutable: a new `Admin\TreatmentPlanItemController` has both `store()` and `update()`. `PatientController::show()` gains a `treatmentPlanItems` prop. `Patients/Show.jsx` gains a fourth tab; no new page. `ProfileController::destroy()`'s existing clinical-record FK guard is extended to cover this new `created_by` foreign key too, closing the same gap that was found and fixed after the fact on the Dental Chart slice.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit (via `php artisan test`), MariaDB (`dentalcrm_testing`).

**Spec:** [`docs/superpowers/specs/2026-08-26-treatment-plans-design.md`](../specs/2026-08-26-treatment-plans-design.md)

## Global Constraints

- No RBAC — every authenticated user can create and update treatment plan items, same as every other staff feature.
- No grouping/parent "Treatment Plan" entity — a patient's plan is simply all their `TreatmentPlanItem` rows.
- No delete of a `TreatmentPlanItem`, ever — no route, no controller method, no UI button, by design. Retiring an item means setting its status to `cancelled`.
- `update()` may only change `status`, `priority`, `estimated_cost`, and `notes`. `treatment`, `tooth_number`, `provider_id`, and `appointment_id` are immutable after creation — present-but-different values for those keys in an update request are silently ignored, not validated or applied.
- No billing/invoice linkage — `estimated_cost` is a plain decimal with no connection to any billing entity.
- `created_by` is set server-side only from `$request->user()->id` and is excluded from `TreatmentPlanItem::$fillable` — never trust a client-supplied value.
- `status` is never accepted from the request on `store()` — every new item starts at `planned`.
- An `appointment_id` must belong to the same patient the entry is being created for — validated against the route-bound `Patient`, not just checked for existence.
- `Patient::treatmentPlanItems()` orders oldest-first (creation order) at the relationship definition (`->oldest('created_at')->oldest('id')`), not in the controller or the UI. This is the opposite order of `dentalRecords()`/`toothConditions()`, which are newest-first logs — this is a working list, not a log.
- Run PHP commands with `"/c/Users/Jann Carl/.config/herd/bin/php.bat"` from the worktree root (this session's `CLAUDE.md` path is stale for this machine's actual user profile).

---

## Task 1: `treatment_plan_items` table, `TreatmentPlanItem` model, `Patient::treatmentPlanItems()`, and the `ProfileController` FK guard

**Files:**
- Create: `database/migrations/2026_08_26_110000_create_treatment_plan_items_table.php`
- Create: `app/Models/TreatmentPlanItem.php`
- Create: `database/factories/TreatmentPlanItemFactory.php`
- Modify: `app/Models/Patient.php`
- Modify: `app/Http/Controllers/ProfileController.php`
- Test: `tests/Feature/TreatmentPlanItemTest.php` (new file)
- Test: `tests/Feature/ProfileTest.php` (modify)

**Interfaces:**
- Consumes: `Patient`, `Provider`, `Appointment`, `User` models (all existing, unmodified) and their factories.
- Produces: `TreatmentPlanItem` model with `const PRIORITIES = ['low', 'medium', 'high']`, `const STATUSES = ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled']`, fillable `['patient_id', 'provider_id', 'appointment_id', 'tooth_number', 'treatment', 'estimated_cost', 'priority', 'status', 'notes']`, and relations `patient()`, `provider()`, `appointment()`, `creator()` (all `BelongsTo`). `TreatmentPlanItemFactory` for test setup. `Patient::treatmentPlanItems(): HasMany`, ordered oldest-first — Task 2's controller and tests rely on this ordering already being correct at the relationship level. `ProfileController::destroy()` now also blocks account deletion for a user who authored a `TreatmentPlanItem`.

- [ ] **Step 1: Write the failing model/relation tests**

Create `tests/Feature/TreatmentPlanItemTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TreatmentPlanItemTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_treatment_plan_item_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'tooth_number' => 14,
            'treatment' => 'Root Canal Treatment',
            'estimated_cost' => 8000,
            'priority' => 'high',
            'status' => 'planned',
            'notes' => 'Patient reports sensitivity.',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $item->patient->id);
        $this->assertSame($provider->id, $item->provider->id);
        $this->assertSame($appointment->id, $item->appointment->id);
        $this->assertSame($user->id, $item->creator->id);
        // Unlike DentalRecord/ToothCondition, this row is mutable — it has
        // a real updated_at, not null.
        $this->assertNotNull($item->updated_at);
    }

    public function test_patient_treatment_plan_items_relation_orders_oldest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->treatmentPlanItems;

        $this->assertSame($older->id, $ordered->first()->id);
        $this->assertSame($newer->id, $ordered->last()->id);
    }

    public function test_patient_treatment_plan_items_relation_breaks_same_second_ties_by_id(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $sameInstant = now();
        $first = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);
        $second = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => $sameInstant,
        ]);

        $ordered = $patient->treatmentPlanItems;

        $this->assertSame($first->id, $ordered->first()->id);
        $this->assertSame($second->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_treatment_plan_items(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('treatment_plan_items', ['id' => $item->id]);
    }

    public function test_deleting_a_provider_nulls_the_treatment_plan_items_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($item->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_treatment_plan_items_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($item->fresh()->appointment_id);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=TreatmentPlanItemTest`
Expected: FAIL — `Class "App\Models\TreatmentPlanItem" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_26_110000_create_treatment_plan_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('tooth_number')->nullable();
            $table->string('treatment');
            $table->decimal('estimated_cost', 10, 2);
            $table->string('priority');
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_items');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/TreatmentPlanItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentPlanItem extends Model
{
    use HasFactory;

    public const PRIORITIES = ['low', 'medium', 'high'];

    public const STATUSES = ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled'];

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'tooth_number',
        'treatment',
        'estimated_cost',
        'priority',
        'status',
        'notes',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/TreatmentPlanItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreatmentPlanItem>
 */
class TreatmentPlanItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'provider_id' => null,
            'appointment_id' => null,
            'tooth_number' => null,
            'treatment' => $this->faker->randomElement([
                'Root Canal Treatment',
                'Dental Filling',
                'Tooth Extraction',
                'Teeth Whitening',
                'Dental Crown',
            ]),
            'estimated_cost' => $this->faker->randomElement([1500, 3000, 5000, 8000, 15000]),
            'priority' => $this->faker->randomElement(TreatmentPlanItem::PRIORITIES),
            'status' => 'planned',
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `Patient`**

In `app/Models/Patient.php`, add this method (after the existing `toothConditions()` method):

```php
    public function treatmentPlanItems(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class)->oldest('created_at')->oldest('id');
    }
```

`HasMany` is already imported at the top of this file (used by `appointments()`, `dentalRecords()`, and `toothConditions()`), so no new import is needed.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=TreatmentPlanItemTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Write the failing `ProfileController` guard test**

`created_by` on `treatment_plan_items` is a required, non-nullable foreign key to `users` with the default `RESTRICT` delete behavior (same as `dental_records.created_by` and `tooth_conditions.created_by`). Without this guard, a staff member who authored a treatment plan item but no dental record or tooth condition would pass `ProfileController::destroy()`'s existing check, get logged out, and then hit an unhandled `QueryException` — this is the exact bug found and fixed after the fact on the Dental Chart slice (see `git show 7c2e123`); this step closes the same gap proactively for this new FK.

In `tests/Feature/ProfileTest.php`, add this import alongside the existing ones:

```php
use App\Models\TreatmentPlanItem;
```

Then add this test method (inside the `ProfileTest` class, after `test_a_user_who_has_authored_a_tooth_condition_cannot_delete_their_account`):

```php
    public function test_a_user_who_has_authored_a_treatment_plan_item_cannot_delete_their_account(): void
    {
        $user = User::factory()->create();
        TreatmentPlanItem::factory()->create([
            'created_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
```

- [ ] **Step 9: Run the test to verify it fails**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=test_a_user_who_has_authored_a_treatment_plan_item_cannot_delete_their_account`
Expected: FAIL — an unhandled `QueryException` (foreign key constraint violation) instead of the expected validation error, because `ProfileController::destroy()` doesn't check `TreatmentPlanItem` yet.

- [ ] **Step 10: Extend the `ProfileController` guard**

In `app/Http/Controllers/ProfileController.php`, add this import alongside the existing ones:

```php
use App\Models\TreatmentPlanItem;
```

Then change:

```php
        if (DentalRecord::where('created_by', $user->id)->exists()
            || ToothCondition::where('created_by', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'password' => 'This account has authored clinical records and cannot be deleted.',
            ]);
        }
```

to:

```php
        if (DentalRecord::where('created_by', $user->id)->exists()
            || ToothCondition::where('created_by', $user->id)->exists()
            || TreatmentPlanItem::where('created_by', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'password' => 'This account has authored clinical records and cannot be deleted.',
            ]);
        }
```

- [ ] **Step 11: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=ProfileTest`
Expected: PASS (all `ProfileTest` tests, including the new one).

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_08_26_110000_create_treatment_plan_items_table.php app/Models/TreatmentPlanItem.php database/factories/TreatmentPlanItemFactory.php app/Models/Patient.php app/Http/Controllers/ProfileController.php tests/Feature/TreatmentPlanItemTest.php tests/Feature/ProfileTest.php
git commit -m "Add treatment_plan_items table, TreatmentPlanItem model, and Patient::treatmentPlanItems()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `PatientController::show()` additions and `TreatmentPlanItemController`

**Files:**
- Create: `app/Http/Controllers/Admin/TreatmentPlanItemController.php`
- Modify: `app/Http/Controllers/Admin/PatientController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TreatmentPlanItemTest.php`

**Interfaces:**
- Consumes: `TreatmentPlanItem` model, `Patient::treatmentPlanItems()` (Task 1). Route-naming/middleware convention from the existing `auth` group in `routes/web.php`.
- Produces: `PatientController::show()`'s Inertia response gains a `treatmentPlanItems` prop (array of `{ id, tooth_number, treatment, estimated_cost, priority, status, notes, provider_name, appointment_start_time, created_at, creator_name }`, oldest first). `POST /patients/{patient}/treatment-plan-items` (name `treatment-plan-items.store`) and `PATCH /patients/{patient}/treatment-plan-items/{treatmentPlanItem}` (name `treatment-plan-items.update`) — Task 3 (frontend) submits to both.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TreatmentPlanItemTest.php` (inside the `TreatmentPlanItemTest` class, before its closing `}`):

```php
    public function test_guest_cannot_create_a_treatment_plan_item(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Root Canal Treatment',
            'estimated_cost' => 8000,
            'priority' => 'high',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_update_a_treatment_plan_item(): void
    {
        $item = TreatmentPlanItem::factory()->create();

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_treatment_plan_item_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Root Canal Treatment',
            'tooth_number' => 14,
            'estimated_cost' => 8000,
            'priority' => 'high',
            'notes' => 'Patient reports sensitivity.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, TreatmentPlanItem::count());
        $item = TreatmentPlanItem::first();
        $this->assertSame($patient->id, $item->patient_id);
        $this->assertSame('Root Canal Treatment', $item->treatment);
        $this->assertSame(14, $item->tooth_number);
        $this->assertSame('8000.00', $item->estimated_cost);
        $this->assertSame('high', $item->priority);
        $this->assertSame('planned', $item->status);
        $this->assertSame('Patient reports sensitivity.', $item->notes);
        $this->assertNull($item->provider_id);
        $this->assertNull($item->appointment_id);
        $this->assertSame($user->id, $item->created_by);
    }

    public function test_new_items_always_start_planned_regardless_of_request_status(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Teeth Whitening',
            'estimated_cost' => 3000,
            'priority' => 'low',
            'status' => 'completed',
        ]);

        $this->assertSame('planned', TreatmentPlanItem::first()->status);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, TreatmentPlanItem::first()->created_by);
    }

    public function test_a_treatment_plan_item_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Crown',
            'estimated_cost' => 15000,
            'priority' => 'medium',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $item = TreatmentPlanItem::first();
        $this->assertSame($provider->id, $item->provider_id);
        $this->assertSame($appointment->id, $item->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'urgent',
        ]);

        $response->assertSessionHasErrors('priority');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_missing_treatment_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'estimated_cost' => 1500,
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('treatment');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_missing_estimated_cost_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('estimated_cost');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_negative_estimated_cost_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => -100,
            'priority' => 'medium',
        ]);

        $response->assertSessionHasErrors('estimated_cost');
        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_a_tooth_number_outside_1_to_32_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $tooHigh = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'tooth_number' => 33,
        ]);
        $tooHigh->assertSessionHasErrors('tooth_number');

        $tooLow = $this->post(route('treatment-plan-items.store', $patient), [
            'treatment' => 'Dental Filling',
            'estimated_cost' => 1500,
            'priority' => 'medium',
            'tooth_number' => 0,
        ]);
        $tooLow->assertSessionHasErrors('tooth_number');

        $this->assertSame(0, TreatmentPlanItem::count());
    }

    public function test_update_changes_status_priority_cost_and_notes(): void
    {
        $this->actingUser();
        $item = TreatmentPlanItem::factory()->create([
            'status' => 'planned',
            'priority' => 'low',
            'estimated_cost' => 1000,
            'notes' => null,
        ]);

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'in_progress',
            'priority' => 'high',
            'estimated_cost' => 2500,
            'notes' => 'Started today.',
        ]);

        $response->assertRedirect();
        $item->refresh();
        $this->assertSame('in_progress', $item->status);
        $this->assertSame('high', $item->priority);
        $this->assertSame('2500.00', $item->estimated_cost);
        $this->assertSame('Started today.', $item->notes);
    }

    public function test_update_ignores_treatment_tooth_number_provider_and_appointment_changes(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'treatment' => 'Original Treatment',
            'tooth_number' => 10,
            'provider_id' => null,
            'appointment_id' => null,
        ]);
        $otherAppointment = Appointment::factory()->create(['patient_id' => $item->patient_id]);

        $this->patch(route('treatment-plan-items.update', ['patient' => $item->patient_id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'scheduled',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
            'treatment' => 'Changed Treatment',
            'tooth_number' => 20,
            'provider_id' => $provider->id,
            'appointment_id' => $otherAppointment->id,
        ]);

        $item->refresh();
        $this->assertSame('Original Treatment', $item->treatment);
        $this->assertSame(10, $item->tooth_number);
        $this->assertNull($item->provider_id);
        $this->assertNull($item->appointment_id);
        $this->assertSame('scheduled', $item->status);
    }

    public function test_update_for_an_item_belonging_to_a_different_patient_404s(): void
    {
        $this->actingUser();
        $otherPatient = Patient::factory()->create();
        $item = TreatmentPlanItem::factory()->create();

        $response = $this->patch(route('treatment-plan-items.update', ['patient' => $otherPatient->id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response->assertNotFound();
        $this->assertSame('planned', $item->fresh()->status);
    }

    public function test_show_page_lists_treatment_plan_items_and_reflects_updates(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $item = TreatmentPlanItem::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'planned',
        ]);

        $this->patch(route('treatment-plan-items.update', ['patient' => $patient->id, 'treatmentPlanItem' => $item->id]), [
            'status' => 'completed',
            'priority' => $item->priority,
            'estimated_cost' => $item->estimated_cost,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('treatmentPlanItems', 1)
            ->where('treatmentPlanItems.0.id', $item->id)
            ->where('treatmentPlanItems.0.status', 'completed')
        );
    }

    public function test_patients_show_page_does_not_include_another_patients_treatment_plan_items(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        TreatmentPlanItem::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('treatmentPlanItems', 0)
        );
    }

    public function test_no_delete_route_exists_for_treatment_plan_items(): void
    {
        $this->assertFalse(Route::has('treatment-plan-items.destroy'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=TreatmentPlanItemTest`
Expected: FAIL — the `treatment-plan-items.store`/`.update` routes don't exist (`RouteNotFoundException`), and `treatmentPlanItems` is missing from the `patients.show` Inertia response.

- [ ] **Step 3: Create `TreatmentPlanItemController`**

Create `app/Http/Controllers/Admin/TreatmentPlanItemController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreatmentPlanItemController extends Controller
{
    /**
     * Every new item starts at status 'planned' — status is never
     * accepted from the request here; it only ever changes via update().
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'treatment' => ['required', 'string', 'max:255'],
            'tooth_number' => ['nullable', 'integer', 'between:1,32'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'estimated_cost' => ['required', 'numeric', 'min:0'],
            'priority' => ['required', Rule::in(TreatmentPlanItem::PRIORITIES)],
            'notes' => ['nullable', 'string'],
        ]);

        // created_by is never trusted from the request — set explicitly
        // from the authenticated user, and it isn't in $fillable either.
        $patient->treatmentPlanItems()->create([
            ...$validated,
            'status' => 'planned',
            'created_by' => $request->user()->id,
        ]);

        return back();
    }

    /**
     * Only status/priority/estimated_cost/notes are editable. treatment,
     * tooth_number, provider_id, and appointment_id are fixed at
     * creation — a wrong one is cancelled and re-entered, not rewritten,
     * so this method never touches them regardless of what the request
     * body contains.
     */
    public function update(Request $request, Patient $patient, TreatmentPlanItem $treatmentPlanItem): RedirectResponse
    {
        abort_unless($treatmentPlanItem->patient_id === $patient->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(TreatmentPlanItem::STATUSES)],
            'priority' => ['required', Rule::in(TreatmentPlanItem::PRIORITIES)],
            'estimated_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $treatmentPlanItem->update($validated);

        return back();
    }
}
```

- [ ] **Step 4: Add the `treatmentPlanItems` prop to `PatientController::show()`**

In `app/Http/Controllers/Admin/PatientController.php`, add this import alongside the existing ones:

```php
use App\Models\TreatmentPlanItem;
```

Then change `show()` from:

```php
            'toothConditions' => $patient->toothConditions()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (ToothCondition $condition) => [
                    'id' => $condition->id,
                    'tooth_number' => $condition->tooth_number,
                    'condition' => $condition->condition,
                    'notes' => $condition->notes,
                    'provider_name' => $condition->provider?->name,
                    'appointment_start_time' => $condition->appointment?->start_time?->toIso8601String(),
                    'created_at' => $condition->created_at->toIso8601String(),
                    'creator_name' => $condition->creator->name,
                ]),
            'providers' => Provider::orderBy('name')->get(['id', 'name']),
```

to:

```php
            'toothConditions' => $patient->toothConditions()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (ToothCondition $condition) => [
                    'id' => $condition->id,
                    'tooth_number' => $condition->tooth_number,
                    'condition' => $condition->condition,
                    'notes' => $condition->notes,
                    'provider_name' => $condition->provider?->name,
                    'appointment_start_time' => $condition->appointment?->start_time?->toIso8601String(),
                    'created_at' => $condition->created_at->toIso8601String(),
                    'creator_name' => $condition->creator->name,
                ]),
            'treatmentPlanItems' => $patient->treatmentPlanItems()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (TreatmentPlanItem $item) => [
                    'id' => $item->id,
                    'tooth_number' => $item->tooth_number,
                    'treatment' => $item->treatment,
                    'estimated_cost' => $item->estimated_cost,
                    'priority' => $item->priority,
                    'status' => $item->status,
                    'notes' => $item->notes,
                    'provider_name' => $item->provider?->name,
                    'appointment_start_time' => $item->appointment?->start_time?->toIso8601String(),
                    'created_at' => $item->created_at->toIso8601String(),
                    'creator_name' => $item->creator->name,
                ]),
            'providers' => Provider::orderBy('name')->get(['id', 'name']),
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import alongside the other `Admin` controller imports:

```php
use App\Http\Controllers\Admin\TreatmentPlanItemController;
```

Change:

```php
    Route::post('/patients/{patient}/tooth-conditions', [ToothConditionController::class, 'store'])
        ->name('tooth-conditions.store');
```

to:

```php
    Route::post('/patients/{patient}/tooth-conditions', [ToothConditionController::class, 'store'])
        ->name('tooth-conditions.store');

    Route::post('/patients/{patient}/treatment-plan-items', [TreatmentPlanItemController::class, 'store'])
        ->name('treatment-plan-items.store');

    Route::patch('/patients/{patient}/treatment-plan-items/{treatmentPlanItem}', [TreatmentPlanItemController::class, 'update'])
        ->name('treatment-plan-items.update');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=TreatmentPlanItemTest`
Expected: PASS (25 tests total: 6 from Task 1 + 19 new).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/TreatmentPlanItemController.php app/Http/Controllers/Admin/PatientController.php routes/web.php tests/Feature/TreatmentPlanItemTest.php
git commit -m "Add PatientController treatmentPlanItems prop and TreatmentPlanItemController

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `Patients/Show.jsx` — Treatment Plan tab

**Files:**
- Modify: `resources/js/Pages/Patients/Show.jsx`

**Interfaces:**
- Consumes: Inertia prop `treatmentPlanItems` (Task 2's exact shape, oldest-first); existing props `patient`, `providers`, `appointments` (unchanged, reused); `route('treatment-plan-items.store', patientId)` and `route('treatment-plan-items.update', { patient, treatmentPlanItem })` (Task 2). Reuses the existing `UPPER_TEETH`/`LOWER_TEETH` arrays and `formatDateTime`/`formatDate` helpers already in this file.
- Produces: a fourth "Treatment Plan" tab on `/patients/{patient}`, no new page/route.

- [ ] **Step 1: Add treatment-plan constants, formatter, and the card component**

In `resources/js/Pages/Patients/Show.jsx`, after the existing `currentConditionFor` function (currently lines 44-46) and before `export default function Show(...)`, add:

```jsx
const TREATMENT_PRIORITIES = ['low', 'medium', 'high'];
const TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled'];
const ACTIVE_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress'];
const ALL_TEETH = [...UPPER_TEETH, ...LOWER_TEETH].sort((a, b) => a - b);

const PRIORITY_COLORS = {
    low: 'bg-gray-100 text-gray-700 border-gray-300',
    medium: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    high: 'bg-red-100 text-red-800 border-red-300',
};

const STATUS_COLORS = {
    planned: 'bg-gray-100 text-gray-700 border-gray-300',
    scheduled: 'bg-blue-100 text-blue-800 border-blue-300',
    in_progress: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    completed: 'bg-green-100 text-green-800 border-green-300',
    cancelled: 'bg-gray-300 text-gray-600 border-gray-400 line-through',
};

function formatPeso(amount) {
    return `₱${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function TreatmentPlanItemCard({ item, onEdit }) {
    return (
        <div className="bg-white shadow rounded p-4 text-sm">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className="font-medium">{item.treatment}</div>
                    <div className="text-gray-500">
                        {item.tooth_number ? `Tooth ${item.tooth_number}` : 'Whole mouth'}
                        {item.provider_name && ` · ${item.provider_name}`}
                        {item.appointment_start_time && ` · linked to ${formatDateTime(item.appointment_start_time)}`}
                    </div>
                </div>
                <button type="button" onClick={onEdit} className="text-sm text-blue-600 shrink-0">
                    Edit
                </button>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <span className="font-medium">{formatPeso(item.estimated_cost)}</span>
                <span className={`inline-block rounded border px-2 py-0.5 text-xs ${PRIORITY_COLORS[item.priority]}`}>
                    {item.priority}
                </span>
                <span className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_COLORS[item.status]}`}>
                    {item.status.replace('_', ' ')}
                </span>
            </div>
            {item.notes && <p className="mt-2">{item.notes}</p>}
            <div className="mt-3 text-xs text-gray-400">
                Logged by {item.creator_name} on {formatDate(item.created_at)}
            </div>
        </div>
    );
}
```

`TreatmentPlanItemCard` is defined at module scope, outside `Show`, so it isn't recreated on every render and can be reused for both the Active and Resolved sections without duplicating the card markup.

- [ ] **Step 2: Accept the `treatmentPlanItems` prop and add tab/modal state**

Change:

```jsx
export default function Show({ patient, dentalRecords, toothConditions, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);
    const [selectedTooth, setSelectedTooth] = useState(null);
```

to:

```jsx
export default function Show({ patient, dentalRecords, toothConditions, treatmentPlanItems, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);
    const [selectedTooth, setSelectedTooth] = useState(null);
    const [showTreatmentModal, setShowTreatmentModal] = useState(false);
    const [editingTreatmentItem, setEditingTreatmentItem] = useState(null);
```

- [ ] **Step 3: Add the treatment-plan forms and their submit/open handlers**

Change:

```jsx
    function submitPatientEdit(e) {
        e.preventDefault();
        patientForm.put(route('patients.update', patient.id), {
            onSuccess: () => setShowEditModal(false),
        });
    }
```

to:

```jsx
    const treatmentForm = useForm({
        treatment: '',
        tooth_number: '',
        provider_id: '',
        appointment_id: '',
        estimated_cost: '',
        priority: 'medium',
        notes: '',
    });

    const treatmentEditForm = useForm({
        status: 'planned',
        priority: 'medium',
        estimated_cost: '',
        notes: '',
    });

    function openTreatmentModal() {
        treatmentForm.reset();
        treatmentForm.clearErrors();
        setShowTreatmentModal(true);
    }

    function submitTreatment(e) {
        e.preventDefault();
        treatmentForm.post(route('treatment-plan-items.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                treatmentForm.reset();
                setShowTreatmentModal(false);
            },
        });
    }

    function openTreatmentEdit(item) {
        treatmentEditForm.clearErrors();
        treatmentEditForm.setData({
            status: item.status,
            priority: item.priority,
            estimated_cost: item.estimated_cost,
            notes: item.notes ?? '',
        });
        setEditingTreatmentItem(item);
    }

    function submitTreatmentEdit(e) {
        e.preventDefault();
        treatmentEditForm.patch(route('treatment-plan-items.update', { patient: patient.id, treatmentPlanItem: editingTreatmentItem.id }), {
            preserveScroll: true,
            onSuccess: () => {
                treatmentEditForm.reset();
                setEditingTreatmentItem(null);
            },
        });
    }

    function submitPatientEdit(e) {
        e.preventDefault();
        patientForm.put(route('patients.update', patient.id), {
            onSuccess: () => setShowEditModal(false),
        });
    }
```

- [ ] **Step 4: Add the fourth tab button**

Change:

```jsx
                    <button
                        type="button"
                        onClick={() => setTab('chart')}
                        className={`pb-2 text-sm font-medium ${tab === 'chart' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Chart
                    </button>
                </div>
```

to:

```jsx
                    <button
                        type="button"
                        onClick={() => setTab('chart')}
                        className={`pb-2 text-sm font-medium ${tab === 'chart' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Chart
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('treatment')}
                        className={`pb-2 text-sm font-medium ${tab === 'treatment' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Treatment Plan
                    </button>
                </div>
```

- [ ] **Step 5: Add the Treatment Plan tab content**

Change (the closing of the `chart` tab block, immediately followed by the closing `</div>` of the tab-content container):

```jsx
                        <div className="mt-6 flex flex-wrap justify-center gap-3 text-xs">
                            <div className="flex items-center gap-1">
                                <span className={`inline-block w-3 h-3 rounded border ${UNMARKED_COLOR}`} />
                                No history
                            </div>
                            {TOOTH_CONDITIONS.map((c) => (
                                <div key={c} className="flex items-center gap-1">
                                    <span className={`inline-block w-3 h-3 rounded border ${CONDITION_COLORS[c]}`} />
                                    {c.replace('_', ' ')}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
```

to:

```jsx
                        <div className="mt-6 flex flex-wrap justify-center gap-3 text-xs">
                            <div className="flex items-center gap-1">
                                <span className={`inline-block w-3 h-3 rounded border ${UNMARKED_COLOR}`} />
                                No history
                            </div>
                            {TOOTH_CONDITIONS.map((c) => (
                                <div key={c} className="flex items-center gap-1">
                                    <span className={`inline-block w-3 h-3 rounded border ${CONDITION_COLORS[c]}`} />
                                    {c.replace('_', ' ')}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {tab === 'treatment' && (
                    <div>
                        <button
                            type="button"
                            onClick={openTreatmentModal}
                            className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
                        >
                            + New Treatment Item
                        </button>

                        <div className="space-y-6">
                            <div>
                                <h4 className="mb-2 text-sm font-semibold text-gray-500">Active</h4>
                                <div className="space-y-3">
                                    {treatmentPlanItems.filter((item) => ACTIVE_TREATMENT_STATUSES.includes(item.status)).map((item) => (
                                        <TreatmentPlanItemCard key={item.id} item={item} onEdit={() => openTreatmentEdit(item)} />
                                    ))}
                                    {treatmentPlanItems.filter((item) => ACTIVE_TREATMENT_STATUSES.includes(item.status)).length === 0 && (
                                        <div className="bg-white shadow rounded p-4 text-sm text-gray-500">No active treatment items.</div>
                                    )}
                                </div>
                            </div>
                            <div>
                                <h4 className="mb-2 text-sm font-semibold text-gray-500">Resolved</h4>
                                <div className="space-y-3">
                                    {treatmentPlanItems.filter((item) => !ACTIVE_TREATMENT_STATUSES.includes(item.status)).map((item) => (
                                        <TreatmentPlanItemCard key={item.id} item={item} onEdit={() => openTreatmentEdit(item)} />
                                    ))}
                                    {treatmentPlanItems.filter((item) => !ACTIVE_TREATMENT_STATUSES.includes(item.status)).length === 0 && (
                                        <div className="bg-white shadow rounded p-4 text-sm text-gray-500">No resolved treatment items.</div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
```

- [ ] **Step 6: Add the New Treatment Item and Edit Treatment Item modals**

Change (the closing of the tooth-condition modal, immediately followed by the closing `</AuthenticatedLayout>`):

```jsx
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => { toothForm.clearErrors(); setSelectedTooth(null); }} className="px-4 py-2 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={toothForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

to:

```jsx
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => { toothForm.clearErrors(); setSelectedTooth(null); }} className="px-4 py-2 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={toothForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {showTreatmentModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitTreatment} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">New treatment item</h3>

                        <div>
                            <label className="block text-sm mb-1">Treatment</label>
                            <input
                                type="text"
                                className="w-full border rounded px-3 py-2"
                                value={treatmentForm.data.treatment}
                                onChange={(e) => treatmentForm.setData('treatment', e.target.value)}
                            />
                            {treatmentForm.errors.treatment && <p className="text-sm text-red-600">{treatmentForm.errors.treatment}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Tooth (optional)</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentForm.data.tooth_number}
                                    onChange={(e) => treatmentForm.setData('tooth_number', e.target.value)}
                                >
                                    <option value="">Whole mouth</option>
                                    {ALL_TEETH.map((n) => <option key={n} value={n}>{n}</option>)}
                                </select>
                                {treatmentForm.errors.tooth_number && <p className="text-sm text-red-600">{treatmentForm.errors.tooth_number}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Priority</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentForm.data.priority}
                                    onChange={(e) => treatmentForm.setData('priority', e.target.value)}
                                >
                                    {TREATMENT_PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
                                </select>
                                {treatmentForm.errors.priority && <p className="text-sm text-red-600">{treatmentForm.errors.priority}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Provider</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentForm.data.provider_id}
                                    onChange={(e) => treatmentForm.setData('provider_id', e.target.value)}
                                >
                                    <option value="">No provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                {treatmentForm.errors.provider_id && <p className="text-sm text-red-600">{treatmentForm.errors.provider_id}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Estimated cost (₱)</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentForm.data.estimated_cost}
                                    onChange={(e) => treatmentForm.setData('estimated_cost', e.target.value)}
                                />
                                {treatmentForm.errors.estimated_cost && <p className="text-sm text-red-600">{treatmentForm.errors.estimated_cost}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Link to appointment (optional)</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={treatmentForm.data.appointment_id}
                                onChange={(e) => treatmentForm.setData('appointment_id', e.target.value)}
                            >
                                <option value="">No linked appointment</option>
                                {appointments.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                    </option>
                                ))}
                            </select>
                            {treatmentForm.errors.appointment_id && <p className="text-sm text-red-600">{treatmentForm.errors.appointment_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={treatmentForm.data.notes}
                                onChange={(e) => treatmentForm.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { treatmentForm.clearErrors(); setShowTreatmentModal(false); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={treatmentForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {editingTreatmentItem && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitTreatmentEdit} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Edit: {editingTreatmentItem.treatment}</h3>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Status</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentEditForm.data.status}
                                    onChange={(e) => treatmentEditForm.setData('status', e.target.value)}
                                >
                                    {TREATMENT_STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                                </select>
                                {treatmentEditForm.errors.status && <p className="text-sm text-red-600">{treatmentEditForm.errors.status}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Priority</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={treatmentEditForm.data.priority}
                                    onChange={(e) => treatmentEditForm.setData('priority', e.target.value)}
                                >
                                    {TREATMENT_PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
                                </select>
                                {treatmentEditForm.errors.priority && <p className="text-sm text-red-600">{treatmentEditForm.errors.priority}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Estimated cost (₱)</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                className="w-full border rounded px-3 py-2"
                                value={treatmentEditForm.data.estimated_cost}
                                onChange={(e) => treatmentEditForm.setData('estimated_cost', e.target.value)}
                            />
                            {treatmentEditForm.errors.estimated_cost && <p className="text-sm text-red-600">{treatmentEditForm.errors.estimated_cost}</p>}
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={treatmentEditForm.data.notes}
                                onChange={(e) => treatmentEditForm.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { treatmentEditForm.clearErrors(); setEditingTreatmentItem(null); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={treatmentEditForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 7: Build the frontend to catch syntax/import errors**

Run: `npm run build`
Expected: build succeeds with no errors (this codebase has no JS test runner — a clean build is the existing verification step for frontend-only changes).

- [ ] **Step 8: Run the full backend test suite**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test`
Expected: PASS, all tests (the full existing suite plus all 25 `TreatmentPlanItemTest` tests and the new `ProfileTest` test) — zero regressions.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Patients/Show.jsx
git commit -m "Add the Treatment Plan tab with status/priority/cost tracking

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Final verification

After Task 3, manually confirm (via `composer run dev` or `php artisan serve` + `npm run dev`) the flows the spec calls out:

1. From a patient's detail page, click the "Treatment Plan" tab → "No active treatment items." and "No resolved treatment items." for a fresh patient.
2. Click "+ New Treatment Item", fill in treatment name, estimated cost, and priority (leave tooth/provider/appointment blank), submit → modal closes, item appears under Active with status "planned".
3. Click "Edit" on that item, change status to "in_progress", submit → card re-renders in place under Active with the new status badge.
4. Edit again, change status to "completed" → the item moves from the Active section to the Resolved section.
5. Create a second item with a tooth number, a provider, and a linked appointment (if the patient has one) → card shows "Tooth N", the provider name, and the linked appointment date.
6. Confirm there is no delete control anywhere on a treatment plan item — only "Edit".
7. Confirm editing never offers a way to change the treatment description, tooth, provider, or linked appointment — only status, priority, cost, and notes.
8. As a sanity check on the `ProfileController` guard: log in as a user who has created a treatment plan item, go to Profile → attempt to delete the account → confirm it's blocked with "This account has authored clinical records and cannot be deleted."

All of these are already covered by `TreatmentPlanItemTest.php` and `ProfileTest.php`; this step is a manual sanity check of the UI wiring, not a substitute for the automated tests.
