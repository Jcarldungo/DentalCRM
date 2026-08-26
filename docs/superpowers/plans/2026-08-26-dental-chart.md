# Dental Chart / Odontogram Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each patient a "Dental Chart" tab on their detail page (`/patients/{patient}`) showing all 32 teeth in a clinical horseshoe layout, color-coded by current condition, with an append-only per-tooth condition history.

**Architecture:** One new table (`tooth_conditions`) and model, with `patient_id` cascading on delete and `provider_id`/`appointment_id` nullable and null-on-delete — the same shape as `dental_records`. A new `Admin\ToothConditionController` with `store()` only — no update/destroy anywhere in this feature, by design. `PatientController::show()` gains a `toothConditions` prop. `Patients/Show.jsx` gains a third tab; no new page.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit (via `php artisan test`), MariaDB (`dentalcrm_testing`).

**Spec:** [`docs/superpowers/specs/2026-08-26-dental-chart-design.md`](../specs/2026-08-26-dental-chart-design.md)

## Global Constraints

- No RBAC — every authenticated user can create and view tooth conditions, same as every other staff feature.
- No per-surface (mesial/distal/occlusal/buccal/lingual) charting — one condition per whole tooth per entry.
- No edit or delete of a `ToothCondition`, ever — no route, no controller method, no UI button, by design.
- Universal numbering (1-32), not FDI or Palmer notation.
- `patient_id`: required, `cascadeOnDelete()`. `provider_id` and `appointment_id`: nullable, `nullOnDelete()`.
- `created_by` is set server-side only from `$request->user()->id` and is excluded from `ToothCondition::$fillable` — never trust a client-supplied value.
- `condition` is always required on submission — unlike `DentalRecord`, there is no "at least one clinical field" check, since `condition` itself is the required content.
- `Patient::toothConditions()` orders newest-first at the relationship definition (`->latest('created_at')`), not in the controller or the UI.
- An `appointment_id` must belong to the same patient the entry is being created for — validated against the route-bound `Patient`, not just checked for existence.
- Current condition per tooth is derived client-side (first entry per `tooth_number` in the newest-first array), never stored as a separate column.
- Run PHP commands with `"/c/Users/JC/.config/herd/bin/php.bat"` from the repo root, `C:\dev JC\DentalCRM` (per this project's `CLAUDE.md`).

---

## Task 1: `tooth_conditions` table, `ToothCondition` model, and `Patient::toothConditions()`

**Files:**
- Create: `database/migrations/2026_08_26_100000_create_tooth_conditions_table.php`
- Create: `app/Models/ToothCondition.php`
- Create: `database/factories/ToothConditionFactory.php`
- Modify: `app/Models/Patient.php`
- Test: `tests/Feature/ToothConditionTest.php` (new file)

**Interfaces:**
- Consumes: `Patient`, `Provider`, `Appointment`, `User` models (all existing, unmodified) and their factories.
- Produces: `ToothCondition` model with `const CONDITIONS = ['healthy', 'caries', 'filling', 'crown', 'missing', 'extraction', 'root_canal', 'implant', 'other']`, `const UPDATED_AT = null`, fillable `['patient_id', 'provider_id', 'appointment_id', 'tooth_number', 'condition', 'notes']`, and relations `patient()`, `provider()`, `appointment()`, `creator()` (all `BelongsTo`). `ToothConditionFactory` for test setup. `Patient::toothConditions(): HasMany`, ordered newest-first — Task 2's controller and tests rely on this ordering already being correct at the relationship level.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ToothConditionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\ToothCondition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ToothConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_tooth_condition_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $condition = ToothCondition::create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'tooth_number' => 14,
            'condition' => 'filling',
            'notes' => 'Composite filling placed.',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $condition->patient->id);
        $this->assertSame($provider->id, $condition->provider->id);
        $this->assertSame($appointment->id, $condition->appointment->id);
        $this->assertSame($user->id, $condition->creator->id);
        $this->assertNull($condition->updated_at);
    }

    public function test_patient_tooth_conditions_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->toothConditions;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_tooth_conditions(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('tooth_conditions', ['id' => $condition->id]);
    }

    public function test_deleting_a_provider_nulls_the_tooth_conditions_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($condition->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_tooth_conditions_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $condition = ToothCondition::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($condition->fresh()->appointment_id);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/JC/.config/herd/bin/php.bat" artisan test --filter=ToothConditionTest`
Expected: FAIL — `Class "App\Models\ToothCondition" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_26_100000_create_tooth_conditions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tooth_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('tooth_number');
            $table->string('condition');
            $table->text('notes')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tooth_conditions');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/ToothCondition.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToothCondition extends Model
{
    use HasFactory;

    public const CONDITIONS = [
        'healthy',
        'caries',
        'filling',
        'crown',
        'missing',
        'extraction',
        'root_canal',
        'implant',
        'other',
    ];

    /**
     * Append-only: there is no updated_at column, and this tells Eloquent
     * to never try to write one.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'tooth_number',
        'condition',
        'notes',
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

Create `database/factories/ToothConditionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\ToothCondition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToothCondition>
 */
class ToothConditionFactory extends Factory
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
            'tooth_number' => $this->faker->numberBetween(1, 32),
            'condition' => $this->faker->randomElement(ToothCondition::CONDITIONS),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `Patient`**

In `app/Models/Patient.php`, add this method (after the existing `dentalRecords()` method):

```php
    public function toothConditions(): HasMany
    {
        return $this->hasMany(ToothCondition::class)->latest('created_at');
    }
```

`HasMany` is already imported at the top of this file (used by `appointments()` and `dentalRecords()`), so no new import is needed.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `"/c/Users/JC/.config/herd/bin/php.bat" artisan test --filter=ToothConditionTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_26_100000_create_tooth_conditions_table.php app/Models/ToothCondition.php database/factories/ToothConditionFactory.php app/Models/Patient.php tests/Feature/ToothConditionTest.php
git commit -m "Add tooth_conditions table, ToothCondition model, and Patient::toothConditions()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `PatientController::show()` additions and `ToothConditionController::store()`

**Files:**
- Create: `app/Http/Controllers/Admin/ToothConditionController.php`
- Modify: `app/Http/Controllers/Admin/PatientController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ToothConditionTest.php`

**Interfaces:**
- Consumes: `ToothCondition` model, `Patient::toothConditions()` (Task 1). Route-naming/middleware convention from the existing `auth` group in `routes/web.php`.
- Produces: `PatientController::show()`'s Inertia response gains a `toothConditions` prop (array of `{ id, tooth_number, condition, notes, provider_name, appointment_start_time, created_at, creator_name }`, newest first). `POST /patients/{patient}/tooth-conditions` (name `tooth-conditions.store`) — Task 3 (frontend) submits `tooth_number`, `condition`, `notes`, `provider_id`, `appointment_id` to this route.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ToothConditionTest.php` (inside the `ToothConditionTest` class, before its closing `}`):

```php
    public function test_guest_cannot_create_a_tooth_condition(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_tooth_condition_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'filling',
            'notes' => 'Composite filling placed.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, ToothCondition::count());
        $condition = ToothCondition::first();
        $this->assertSame($patient->id, $condition->patient_id);
        $this->assertSame(14, $condition->tooth_number);
        $this->assertSame('filling', $condition->condition);
        $this->assertSame('Composite filling placed.', $condition->notes);
        $this->assertNull($condition->provider_id);
        $this->assertNull($condition->appointment_id);
        $this->assertSame($user->id, $condition->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, ToothCondition::first()->created_by);
    }

    public function test_a_tooth_condition_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 3,
            'condition' => 'extraction',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $condition = ToothCondition::first();
        $this->assertSame($provider->id, $condition->provider_id);
        $this->assertSame($appointment->id, $condition->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'healthy',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_an_invalid_condition_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
            'condition' => 'not-a-real-condition',
        ]);

        $response->assertSessionHasErrors('condition');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_missing_condition_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 14,
        ]);

        $response->assertSessionHasErrors('condition');
        $this->assertSame(0, ToothCondition::count());
    }

    public function test_a_tooth_number_outside_1_to_32_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $tooHigh = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 33,
            'condition' => 'healthy',
        ]);
        $tooHigh->assertSessionHasErrors('tooth_number');

        $tooLow = $this->post(route('tooth-conditions.store', $patient), [
            'tooth_number' => 0,
            'condition' => 'healthy',
        ]);
        $tooLow->assertSessionHasErrors('tooth_number');

        $this->assertSame(0, ToothCondition::count());
    }

    public function test_current_state_for_a_tooth_is_derivable_as_the_newest_entry(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $older = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'tooth_number' => 14,
            'condition' => 'caries',
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = ToothCondition::factory()->create([
            'patient_id' => $patient->id,
            'tooth_number' => 14,
            'condition' => 'filling',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('toothConditions', 2)
            ->where('toothConditions.0.id', $newer->id)
            ->where('toothConditions.0.condition', 'filling')
            ->where('toothConditions.1.id', $older->id)
        );
    }

    public function test_patients_show_page_does_not_include_another_patients_tooth_conditions(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        ToothCondition::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('toothConditions', 0)
        );
    }

    public function test_no_edit_or_delete_routes_exist_for_tooth_conditions(): void
    {
        $this->assertFalse(Route::has('tooth-conditions.update'));
        $this->assertFalse(Route::has('tooth-conditions.destroy'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/JC/.config/herd/bin/php.bat" artisan test --filter=ToothConditionTest`
Expected: FAIL — the `tooth-conditions.store` route doesn't exist (`RouteNotFoundException`), and `toothConditions` is missing from the `patients.show` Inertia response.

- [ ] **Step 3: Create `ToothConditionController`**

Create `app/Http/Controllers/Admin/ToothConditionController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\ToothCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToothConditionController extends Controller
{
    /**
     * Append-only: there is deliberately no update()/destroy() here, no
     * matching routes, and no UI to reach them. A correction is a new
     * entry, not an edit to this one.
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'tooth_number' => ['required', 'integer', 'between:1,32'],
            'condition' => ['required', Rule::in(ToothCondition::CONDITIONS)],
            'notes' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
        ]);

        // created_by is never trusted from the request — set explicitly
        // from the authenticated user, and it isn't in $fillable either.
        $patient->toothConditions()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return back();
    }
}
```

- [ ] **Step 4: Add the `toothConditions` prop to `PatientController::show()`**

In `app/Http/Controllers/Admin/PatientController.php`, add this import alongside the existing ones:

```php
use App\Models\ToothCondition;
```

Then change `show()` from:

```php
    public function show(Patient $patient): Response
    {
        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'dentalRecords' => $patient->dentalRecords()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (DentalRecord $record) => [
                    'id' => $record->id,
                    'type' => $record->type,
                    'provider_name' => $record->provider?->name,
                    'appointment_start_time' => $record->appointment?->start_time?->toIso8601String(),
                    'examination' => $record->examination,
                    'diagnosis' => $record->diagnosis,
                    'procedure' => $record->procedure,
                    'notes' => $record->notes,
                    'created_at' => $record->created_at->toIso8601String(),
                    'creator_name' => $record->creator->name,
                ]),
            'providers' => Provider::orderBy('name')->get(['id', 'name']),
            'appointments' => $patient->appointments()
                ->orderByDesc('start_time')
                ->get(['id', 'start_time', 'type']),
        ]);
    }
```

to:

```php
    public function show(Patient $patient): Response
    {
        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'dentalRecords' => $patient->dentalRecords()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (DentalRecord $record) => [
                    'id' => $record->id,
                    'type' => $record->type,
                    'provider_name' => $record->provider?->name,
                    'appointment_start_time' => $record->appointment?->start_time?->toIso8601String(),
                    'examination' => $record->examination,
                    'diagnosis' => $record->diagnosis,
                    'procedure' => $record->procedure,
                    'notes' => $record->notes,
                    'created_at' => $record->created_at->toIso8601String(),
                    'creator_name' => $record->creator->name,
                ]),
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
            'appointments' => $patient->appointments()
                ->orderByDesc('start_time')
                ->get(['id', 'start_time', 'type']),
        ]);
    }
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add the import alongside the other `Admin` controller imports:

```php
use App\Http\Controllers\Admin\ToothConditionController;
```

Change:

```php
    Route::post('/patients/{patient}/dental-records', [DentalRecordController::class, 'store'])
        ->name('dental-records.store');
```

to:

```php
    Route::post('/patients/{patient}/dental-records', [DentalRecordController::class, 'store'])
        ->name('dental-records.store');

    Route::post('/patients/{patient}/tooth-conditions', [ToothConditionController::class, 'store'])
        ->name('tooth-conditions.store');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `"/c/Users/JC/.config/herd/bin/php.bat" artisan test --filter=ToothConditionTest`
Expected: PASS (16 tests total: 5 from Task 1 + 11 new).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/ToothConditionController.php app/Http/Controllers/Admin/PatientController.php routes/web.php tests/Feature/ToothConditionTest.php
git commit -m "Add PatientController toothConditions prop and ToothConditionController::store()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `Patients/Show.jsx` — Dental Chart tab

**Files:**
- Modify: `resources/js/Pages/Patients/Show.jsx`

**Interfaces:**
- Consumes: Inertia prop `toothConditions` (Task 2's exact shape, newest-first); existing props `patient`, `providers`, `appointments` (unchanged, reused); `route('tooth-conditions.store', patientId)` (Task 2).
- Produces: a third "Dental Chart" tab on `/patients/{patient}`, no new page/route.

- [ ] **Step 1: Add tooth-chart constants and the current-condition helper**

In `resources/js/Pages/Patients/Show.jsx`, after the existing `formatDate` function (currently lines 17-23) and before `export default function Show(...)`, add:

```jsx
const TOOTH_CONDITIONS = ['healthy', 'caries', 'filling', 'crown', 'missing', 'extraction', 'root_canal', 'implant', 'other'];

const CONDITION_COLORS = {
    healthy: 'bg-green-100 text-green-800 border-green-300',
    caries: 'bg-red-100 text-red-800 border-red-300',
    filling: 'bg-blue-100 text-blue-800 border-blue-300',
    crown: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    missing: 'bg-gray-200 text-gray-500 border-gray-300',
    extraction: 'bg-gray-400 text-white border-gray-500',
    root_canal: 'bg-purple-100 text-purple-800 border-purple-300',
    implant: 'bg-teal-100 text-teal-800 border-teal-300',
    other: 'bg-orange-100 text-orange-800 border-orange-300',
};

const UNMARKED_COLOR = 'bg-white text-gray-400 border-gray-200';

const UPPER_TEETH = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
const LOWER_TEETH = [32, 31, 30, 29, 28, 27, 26, 25, 24, 23, 22, 21, 20, 19, 18, 17];

function currentConditionFor(toothConditions, toothNumber) {
    return toothConditions.find((c) => c.tooth_number === toothNumber) ?? null;
}
```

`toothConditions` is already newest-first (from `Patient::toothConditions()`'s `->latest('created_at')`), so `.find()` returning the first match per tooth is already the current condition — no client-side sorting needed.

- [ ] **Step 2: Accept the `toothConditions` prop and add chart state**

Change:

```jsx
export default function Show({ patient, dentalRecords, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);
```

to:

```jsx
export default function Show({ patient, dentalRecords, toothConditions, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);
    const [selectedTooth, setSelectedTooth] = useState(null);
```

- [ ] **Step 3: Add the tooth-condition form and its submit/open handlers**

Change:

```jsx
    const recordForm = useForm({
        type: 'consultation',
        provider_id: '',
        appointment_id: '',
        examination: '',
        diagnosis: '',
        procedure: '',
        notes: '',
    });

    function submitPatientEdit(e) {
```

to:

```jsx
    const recordForm = useForm({
        type: 'consultation',
        provider_id: '',
        appointment_id: '',
        examination: '',
        diagnosis: '',
        procedure: '',
        notes: '',
    });

    const toothForm = useForm({
        tooth_number: null,
        condition: 'healthy',
        notes: '',
        provider_id: '',
        appointment_id: '',
    });

    function openTooth(toothNumber) {
        toothForm.reset();
        toothForm.setData({
            tooth_number: toothNumber,
            condition: 'healthy',
            notes: '',
            provider_id: '',
            appointment_id: '',
        });
        setSelectedTooth(toothNumber);
    }

    function submitToothCondition(e) {
        e.preventDefault();
        toothForm.post(route('tooth-conditions.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                toothForm.reset();
                setSelectedTooth(null);
            },
        });
    }

    function submitPatientEdit(e) {
```

- [ ] **Step 4: Add the "Dental Chart" tab button**

Change:

```jsx
                    <button
                        type="button"
                        onClick={() => setTab('records')}
                        className={`pb-2 text-sm font-medium ${tab === 'records' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Records
                    </button>
                </div>
```

to:

```jsx
                    <button
                        type="button"
                        onClick={() => setTab('records')}
                        className={`pb-2 text-sm font-medium ${tab === 'records' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Records
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('chart')}
                        className={`pb-2 text-sm font-medium ${tab === 'chart' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Chart
                    </button>
                </div>
```

- [ ] **Step 5: Add the chart tab content**

Change (the end of the `records` tab block, immediately followed by the closing of the `py-8` wrapper `<div>`):

```jsx
                            {dentalRecords.length === 0 && (
                                <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                    No dental records yet.
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
```

to:

```jsx
                            {dentalRecords.length === 0 && (
                                <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                    No dental records yet.
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'chart' && (
                    <div className="bg-white shadow rounded p-6">
                        <div className="mb-2 flex justify-center gap-1">
                            {UPPER_TEETH.map((n) => {
                                const current = currentConditionFor(toothConditions, n);
                                return (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => openTooth(n)}
                                        className={`w-9 h-9 rounded border text-xs font-medium ${current ? CONDITION_COLORS[current.condition] : UNMARKED_COLOR}`}
                                    >
                                        {n}
                                    </button>
                                );
                            })}
                        </div>
                        <div className="flex justify-center gap-1">
                            {LOWER_TEETH.map((n) => {
                                const current = currentConditionFor(toothConditions, n);
                                return (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => openTooth(n)}
                                        className={`w-9 h-9 rounded border text-xs font-medium ${current ? CONDITION_COLORS[current.condition] : UNMARKED_COLOR}`}
                                    >
                                        {n}
                                    </button>
                                );
                            })}
                        </div>

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

- [ ] **Step 6: Add the tooth history + add-entry modal**

Change (the end of the New Dental Record modal block, immediately followed by the closing of `AuthenticatedLayout`):

```jsx
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowRecordModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={recordForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
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

to:

```jsx
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowRecordModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={recordForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {selectedTooth !== null && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <div className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Tooth {selectedTooth}</h3>

                        <div className="space-y-3">
                            {toothConditions.filter((c) => c.tooth_number === selectedTooth).map((c) => (
                                <div key={c.id} className="bg-gray-50 rounded p-3 text-sm">
                                    <div className="text-gray-500">
                                        {c.condition.replace('_', ' ')}
                                        {c.provider_name && ` · ${c.provider_name}`}
                                        {c.appointment_start_time && ` · linked to ${formatDateTime(c.appointment_start_time)}`}
                                    </div>
                                    {c.notes && <p className="mt-2">{c.notes}</p>}
                                    <div className="mt-2 text-xs text-gray-400">
                                        Logged by {c.creator_name} on {formatDate(c.created_at)}
                                    </div>
                                </div>
                            ))}
                            {toothConditions.filter((c) => c.tooth_number === selectedTooth).length === 0 && (
                                <div className="text-sm text-gray-500">No history for this tooth yet.</div>
                            )}
                        </div>

                        <form onSubmit={submitToothCondition} className="space-y-4 border-t pt-4">
                            <h4 className="text-sm font-semibold">+ Add entry</h4>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm mb-1">Condition</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={toothForm.data.condition}
                                        onChange={(e) => toothForm.setData('condition', e.target.value)}
                                    >
                                        {TOOTH_CONDITIONS.map((c) => <option key={c} value={c}>{c.replace('_', ' ')}</option>)}
                                    </select>
                                    {toothForm.errors.condition && <p className="text-sm text-red-600">{toothForm.errors.condition}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm mb-1">Provider</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={toothForm.data.provider_id}
                                        onChange={(e) => toothForm.setData('provider_id', e.target.value)}
                                    >
                                        <option value="">No provider</option>
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                    {toothForm.errors.provider_id && <p className="text-sm text-red-600">{toothForm.errors.provider_id}</p>}
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm mb-1">Link to appointment (optional)</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={toothForm.data.appointment_id}
                                    onChange={(e) => toothForm.setData('appointment_id', e.target.value)}
                                >
                                    <option value="">No linked appointment</option>
                                    {appointments.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                        </option>
                                    ))}
                                </select>
                                {toothForm.errors.appointment_id && <p className="text-sm text-red-600">{toothForm.errors.appointment_id}</p>}
                            </div>

                            <div>
                                <label className="block text-sm mb-1">Notes</label>
                                <textarea
                                    className="w-full border rounded px-3 py-2"
                                    rows={2}
                                    value={toothForm.data.notes}
                                    onChange={(e) => toothForm.setData('notes', e.target.value)}
                                />
                            </div>

                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setSelectedTooth(null)} className="px-4 py-2 text-sm">
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

- [ ] **Step 7: Build the frontend to catch syntax/import errors**

Run: `npm run build`
Expected: build succeeds with no errors (this codebase has no JS test runner — a clean build is the existing verification step for frontend-only changes).

- [ ] **Step 8: Run the full backend test suite**

Run: `"/c/Users/JC/.config/herd/bin/php.bat" artisan test`
Expected: PASS, all tests (the full existing suite plus all 16 `ToothConditionTest` tests) — zero regressions.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Patients/Show.jsx
git commit -m "Add the Dental Chart tab with per-tooth condition history

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Final verification

After Task 3, manually confirm (via `composer run dev` or `php artisan serve` + `npm run dev`) the flows the spec calls out:

1. From a patient's detail page, click the "Dental Chart" tab → two rows of 16 numbered boxes in horseshoe order (upper: 1-16 left to right; lower: 32-17 left to right, so 16/17 and 1/32 line up vertically), all unmarked (neutral color) for a fresh patient.
2. Click a tooth → modal opens showing "No history for this tooth yet." and the add-entry form.
3. Submit with condition "filling" and a note → modal closes, that tooth's box is now colored per the `filling` legend entry.
4. Click that same tooth again → its one history entry appears, newest first, with "Logged by \<your name\> on \<today's date\>".
5. Add a second entry for the same tooth with a different condition → box color updates to the new condition; history shows both entries, newest first.
6. Add an entry with a provider and a linked appointment (if the patient has one) → history entry shows both.
7. Confirm there is no Edit or Delete control anywhere on a tooth condition entry.
8. Confirm the legend below the chart lists all 9 conditions plus "No history", each with a distinct color.

All of these are already covered by `ToothConditionTest.php`; this step is a manual sanity check of the UI wiring, not a substitute for the automated tests.
