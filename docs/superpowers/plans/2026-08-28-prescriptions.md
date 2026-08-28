# Prescriptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Prescriptions tab to `/patients/{patient}` where staff record one medication per entry, attribute it to a provider/appointment, and later discontinue it — with clinical content immutable after creation.

**Architecture:** One `prescriptions` table + `Prescription` model (mutable only in `status`/`discontinued_at`/`discontinued_reason`), a `PrescriptionController` with `store` + `update` (update = one-way discontinue), a `prescriptions` prop added to `PatientController::show()`, and a new `PrescriptionsTab.jsx` component wired into the existing `Patients/Show.jsx` as a fifth tab. Three date/currency formatters move out of `Show.jsx` into a shared `resources/js/Pages/Patients/format.js`.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit, MariaDB (`dentalcrm_testing`).

**Spec:** `docs/superpowers/specs/2026-08-28-prescriptions-design.md`

## Global Constraints

- PHP runs via Herd: use `"$HOME/.config/herd/bin/php.bat" artisan ...` for all artisan/test commands. `npm` is on PATH normally. Run everything from the repo root.
- Tests run against MariaDB `dentalcrm_testing` (must already exist), not SQLite.
- No RBAC — every authenticated user can create/view/discontinue prescriptions.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump`, no unused imports, no commented-out code.
- Staff-facing controllers live in `App\Http\Controllers\Admin\`. Route names are unprefixed.
- Tests are flat: `tests/Feature/<Name>Test.php`, no subdirectories.
- Every controller action returns `back()` (Inertia redirect), matching the existing `PatientController` / `TreatmentPlanItemController`.
- `created_by` is never in `$fillable` and never read from request input — set server-side from `$request->user()->id` by direct property assignment.
- Append-only-content rule for prescriptions is enforced by there being **no route** to edit/delete clinical content — not by hiding UI.
- Prices are Philippine pesos (`₱`).
- Commit after every task with a message in the style of recent history (short imperative subject; end with the `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>` trailer only if the repo's other commits have it — they do not, so omit trailers to match).

---

## File Structure

**Create:**
- `database/migrations/2026_08_28_120000_create_prescriptions_table.php` — schema
- `app/Models/Prescription.php` — model, `STATUSES` const, relations, `$fillable`, `discontinued_at` cast
- `database/factories/PrescriptionFactory.php` — default active prescription + `discontinued()` state
- `app/Http/Controllers/Admin/PrescriptionController.php` — `store` + `update`
- `resources/js/Pages/Patients/format.js` — `formatDate`, `formatDateTime`, `formatPeso` (moved from `Show.jsx`)
- `resources/js/Pages/Patients/PrescriptionsTab.jsx` — tab body + New Prescription modal + Discontinue modal
- `tests/Feature/PrescriptionTest.php` — feature tests

**Modify:**
- `app/Models/Patient.php` — add `prescriptions(): HasMany`
- `app/Http/Controllers/Admin/PatientController.php` — add `prescriptions` prop to `show()`
- `routes/web.php` — add the two prescription routes inside the `auth` group
- `resources/js/Pages/Patients/Show.jsx` — 5th tab button, render `<PrescriptionsTab>`, import formatters from `./format` and delete the three local copies
- `CLAUDE.md` — add "Phase 6, sub-project 4" bullet under "Shipped so far"

---

## Task 1: Database layer — migration, model, Patient relation, factory

**Files:**
- Create: `database/migrations/2026_08_28_120000_create_prescriptions_table.php`
- Create: `app/Models/Prescription.php`
- Create: `database/factories/PrescriptionFactory.php`
- Modify: `app/Models/Patient.php`
- Test: `tests/Feature/PrescriptionTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `App\Models\Prescription` with `const STATUSES = ['active', 'discontinued']`; relations `patient()`, `provider()`, `appointment()`, `creator()` (all `BelongsTo`); `$fillable = ['patient_id','provider_id','appointment_id','medication','dosage','frequency','duration','quantity','instructions']`; `$casts = ['discontinued_at' => 'datetime']`.
  - `Patient::prescriptions(): HasMany` — ordered `->latest('created_at')->latest('id')`.
  - `PrescriptionFactory` — default state is a valid `active` prescription with `provider_id`/`appointment_id`/`duration`/`quantity`/`instructions` all `null`, `created_by => User::factory()`; `discontinued()` state sets `status => 'discontinued'`, `discontinued_at => now()`.
  - Table `prescriptions` columns: `id`, `patient_id` (FK cascadeOnDelete), `provider_id` (nullable FK nullOnDelete), `appointment_id` (nullable FK nullOnDelete), `medication`/`dosage`/`frequency` (string), `duration`/`quantity` (string nullable), `instructions` (text nullable), `status` (string default `active`), `discontinued_at` (timestamp nullable), `discontinued_reason` (string nullable), `created_by` (FK → users), `timestamps()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PrescriptionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_prescription_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $rx = Prescription::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $rx->patient->id);
        $this->assertSame($provider->id, $rx->provider->id);
        $this->assertSame($appointment->id, $rx->appointment->id);
        $this->assertSame($user->id, $rx->creator->id);
        $this->assertSame('active', $rx->status);
        $this->assertNotNull($rx->updated_at);
    }

    public function test_patient_prescriptions_relation_orders_newest_first_with_id_tiebreak(): void
    {
        $patient = Patient::factory()->create();
        $sameInstant = now();
        $first = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => $sameInstant]);
        $second = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => $sameInstant]);
        $older = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);

        $ordered = $patient->prescriptions;

        $this->assertSame($second->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_prescriptions(): void
    {
        $rx = Prescription::factory()->create();

        $rx->patient->delete();

        $this->assertDatabaseMissing('prescriptions', ['id' => $rx->id]);
    }

    public function test_deleting_a_provider_nulls_the_prescription_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $rx = Prescription::factory()->create(['provider_id' => $provider->id]);

        $provider->delete();

        $this->assertNull($rx->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_prescription_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $rx = Prescription::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ]);

        $appointment->delete();

        $this->assertNull($rx->fresh()->appointment_id);
    }

    public function test_discontinued_factory_state(): void
    {
        $rx = Prescription::factory()->discontinued()->create();

        $this->assertSame('discontinued', $rx->status);
        $this->assertNotNull($rx->discontinued_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: FAIL — `Class "App\Models\Prescription" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_28_120000_create_prescriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('medication');
            $table->string('dosage');
            $table->string('frequency');
            $table->string('duration')->nullable();
            $table->string('quantity')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('discontinued_at')->nullable();
            $table->string('discontinued_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/Prescription.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'discontinued'];

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'medication',
        'dosage',
        'frequency',
        'duration',
        'quantity',
        'instructions',
    ];

    protected $casts = [
        'discontinued_at' => 'datetime',
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

- [ ] **Step 5: Write the factory**

Create `database/factories/PrescriptionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'provider_id' => null,
            'appointment_id' => null,
            'medication' => $this->faker->randomElement([
                'Amoxicillin',
                'Ibuprofen',
                'Paracetamol',
                'Metronidazole',
                'Chlorhexidine mouthwash',
            ]),
            'dosage' => $this->faker->randomElement(['250 mg', '500 mg', '400 mg', '0.2%']),
            'frequency' => $this->faker->randomElement(['Once daily', '2 times daily', '3 times daily', 'Every 8 hours']),
            'duration' => null,
            'quantity' => null,
            'instructions' => null,
            'status' => 'active',
            'discontinued_at' => null,
            'discontinued_reason' => null,
            'created_by' => User::factory(),
        ];
    }

    public function discontinued(): static
    {
        return $this->state(fn () => [
            'status' => 'discontinued',
            'discontinued_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Add the Patient relation**

Modify `app/Models/Patient.php` — add after `treatmentPlanItems()`:

```php
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->latest('created_at')->latest('id');
    }
```

(`HasMany` is already imported in this file.)

- [ ] **Step 7: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Models/Prescription.php app/Models/Patient.php database/migrations/2026_08_28_120000_create_prescriptions_table.php database/factories/PrescriptionFactory.php tests/Feature/PrescriptionTest.php
git commit -m "Add prescriptions table, Prescription model, and Patient::prescriptions()"
```

---

## Task 2: `PrescriptionController::store` + route

**Files:**
- Create: `app/Http/Controllers/Admin/PrescriptionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PrescriptionTest.php`

**Interfaces:**
- Consumes: `App\Models\Prescription`, `Patient::prescriptions()` from Task 1.
- Produces:
  - Route `POST /patients/{patient}/prescriptions` → `Admin\PrescriptionController@store`, name `prescriptions.store`.
  - `PrescriptionController::store(Request $request, Patient $patient): RedirectResponse` — validates and creates a `Prescription`, `status` left to the `active` column default, `created_by` set from `$request->user()->id`, returns `back()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PrescriptionTest.php`:

```php
    public function test_guest_cannot_create_a_prescription(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, Prescription::count());
    }

    public function test_a_prescription_can_be_created(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'duration' => '7 days',
            'quantity' => '21 capsules',
            'instructions' => 'Take after meals. Finish the full course.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Prescription::count());
        $rx = Prescription::first();
        $this->assertSame($patient->id, $rx->patient_id);
        $this->assertSame('Amoxicillin', $rx->medication);
        $this->assertSame('500 mg', $rx->dosage);
        $this->assertSame('3 times daily', $rx->frequency);
        $this->assertSame('7 days', $rx->duration);
        $this->assertSame('21 capsules', $rx->quantity);
        $this->assertSame('Take after meals. Finish the full course.', $rx->instructions);
        $this->assertSame('active', $rx->status);
        $this->assertNull($rx->discontinued_at);
        $this->assertNull($rx->provider_id);
        $this->assertNull($rx->appointment_id);
        $this->assertSame($user->id, $rx->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Ibuprofen',
            'dosage' => '400 mg',
            'frequency' => 'Every 8 hours',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, Prescription::first()->created_by);
    }

    public function test_status_is_always_active_on_create_regardless_of_request(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Ibuprofen',
            'dosage' => '400 mg',
            'frequency' => 'Every 8 hours',
            'status' => 'discontinued',
        ]);

        $this->assertSame('active', Prescription::first()->status);
    }

    public function test_a_missing_required_field_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $complete = [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
        ];

        foreach (['medication', 'dosage', 'frequency'] as $missing) {
            $payload = $complete;
            unset($payload[$missing]);

            $response = $this->post(route('prescriptions.store', $patient), $payload);

            $response->assertSessionHasErrors($missing);
        }

        $this->assertSame(0, Prescription::count());
    }

    public function test_a_prescription_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Metronidazole',
            'dosage' => '400 mg',
            'frequency' => '3 times daily',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
        ]);

        $response->assertRedirect();
        $rx = Prescription::first();
        $this->assertSame($provider->id, $rx->provider_id);
        $this->assertSame($appointment->id, $rx->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'appointment_id' => $otherPatientsAppointment->id,
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, Prescription::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('prescriptions.store', $patient), [
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
            'frequency' => '3 times daily',
            'provider_id' => 999999,
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, Prescription::count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: FAIL — `Route [prescriptions.store] not defined`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/PrescriptionController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A prescription's clinical content — medication, dosage, frequency,
 * duration, quantity, instructions, and both FK links — is fixed at
 * creation. The only post-creation change is a one-way active ->
 * discontinued flip via update(). There is deliberately no destroy()
 * here, no matching route, and no UI control to reach one; a wrong
 * prescription is discontinued and re-entered, not rewritten.
 */
class PrescriptionController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'medication' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'frequency' => ['required', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
        ]);

        // created_by is never trusted from the request and isn't $fillable;
        // set it explicitly. status is left to its 'active' column default.
        $rx = $patient->prescriptions()->make($validated);
        $rx->created_by = $request->user()->id;
        $rx->save();

        return back();
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/web.php`:

Add the import alongside the other `Admin` controller imports:
```php
use App\Http\Controllers\Admin\PrescriptionController;
```

Inside the `Route::middleware('auth')->group(...)`, after the `treatment-plan-items` routes:
```php
    Route::post('/patients/{patient}/prescriptions', [PrescriptionController::class, 'store'])
        ->name('prescriptions.store');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PrescriptionController.php routes/web.php tests/Feature/PrescriptionTest.php
git commit -m "Add PrescriptionController::store and the prescriptions.store route"
```

---

## Task 3: `PrescriptionController::update` — the discontinue action

**Files:**
- Modify: `app/Http/Controllers/Admin/PrescriptionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PrescriptionTest.php`

**Interfaces:**
- Consumes: `PrescriptionController` and `prescriptions.store` route from Task 2.
- Produces:
  - Route `PATCH /patients/{patient}/prescriptions/{prescription}` → `Admin\PrescriptionController@update`, name `prescriptions.update`. Route param `prescription` binds to `App\Models\Prescription $prescription`.
  - `PrescriptionController::update(Request $request, Patient $patient, Prescription $prescription): RedirectResponse` — `abort_unless` patient match (404) and status `active` (403), validates only `discontinued_reason`, sets `status = 'discontinued'`, `discontinued_at = now()`, `discontinued_reason`, returns `back()`. Never reads any drug field from the request.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PrescriptionTest.php`:

```php
    public function test_guest_cannot_discontinue_a_prescription(): void
    {
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Course completed',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame('active', $rx->fresh()->status);
    }

    public function test_discontinue_sets_status_timestamp_and_reason(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Patient reported a rash',
        ]);

        $response->assertRedirect();
        $rx->refresh();
        $this->assertSame('discontinued', $rx->status);
        $this->assertNotNull($rx->discontinued_at);
        $this->assertSame('Patient reported a rash', $rx->discontinued_reason);
    }

    public function test_discontinue_reason_is_optional(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), []);

        $response->assertRedirect();
        $rx->refresh();
        $this->assertSame('discontinued', $rx->status);
        $this->assertNull($rx->discontinued_reason);
    }

    public function test_discontinue_ignores_drug_fields_in_the_request_body(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->create([
            'medication' => 'Amoxicillin',
            'dosage' => '500 mg',
        ]);

        $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'medication' => 'HACKED',
            'dosage' => 'HACKED',
            'status' => 'active',
        ]);

        $rx->refresh();
        $this->assertSame('Amoxicillin', $rx->medication);
        $this->assertSame('500 mg', $rx->dosage);
        $this->assertSame('discontinued', $rx->status);
    }

    public function test_discontinue_is_one_way(): void
    {
        $this->actingUser();
        $rx = Prescription::factory()->discontinued()->create(['discontinued_reason' => 'Original reason']);

        $response = $this->patch(route('prescriptions.update', ['patient' => $rx->patient_id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Second attempt',
        ]);

        $response->assertForbidden();
        $this->assertSame('Original reason', $rx->fresh()->discontinued_reason);
    }

    public function test_discontinue_for_a_prescription_belonging_to_a_different_patient_404s(): void
    {
        $this->actingUser();
        $otherPatient = Patient::factory()->create();
        $rx = Prescription::factory()->create();

        $response = $this->patch(route('prescriptions.update', ['patient' => $otherPatient->id, 'prescription' => $rx->id]), [
            'discontinued_reason' => 'Course completed',
        ]);

        $response->assertNotFound();
        $this->assertSame('active', $rx->fresh()->status);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: FAIL — `Route [prescriptions.update] not defined`.

- [ ] **Step 3: Add `update()` to the controller**

Modify `app/Http/Controllers/Admin/PrescriptionController.php`:

Add to the imports:
```php
use App\Models\Prescription;
```

Add the method after `store()`:

```php
    /**
     * The discontinue action, and nothing else. Only status /
     * discontinued_at / discontinued_reason change here — drug fields in
     * the request body are never read, so clinical content cannot be
     * edited through this endpoint. A prescription can be discontinued
     * only once.
     */
    public function update(Request $request, Patient $patient, Prescription $prescription): RedirectResponse
    {
        abort_unless($prescription->patient_id === $patient->id, 404);
        abort_unless($prescription->status === 'active', 403);

        $validated = $request->validate([
            'discontinued_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // status / discontinued_at / discontinued_reason are intentionally
        // not $fillable — set them by direct assignment, not mass-assignment,
        // so nothing in the request body can reach them.
        $prescription->status = 'discontinued';
        $prescription->discontinued_at = now();
        $prescription->discontinued_reason = $validated['discontinued_reason'] ?? null;
        $prescription->save();

        return back();
    }
```

- [ ] **Step 4: Add the route**

Modify `routes/web.php` — directly after the `prescriptions.store` route:

```php
    Route::patch('/patients/{patient}/prescriptions/{prescription}', [PrescriptionController::class, 'update'])
        ->name('prescriptions.update');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PrescriptionController.php routes/web.php tests/Feature/PrescriptionTest.php
git commit -m "Add PrescriptionController::update discontinue action"
```

---

## Task 4: `PatientController::show()` prescriptions prop

**Files:**
- Modify: `app/Http/Controllers/Admin/PatientController.php`
- Test: `tests/Feature/PrescriptionTest.php`

**Interfaces:**
- Consumes: `Prescription` model + `Patient::prescriptions()` (Task 1), `prescriptions.store` / `prescriptions.update` (Tasks 2-3).
- Produces: `Patients/Show` Inertia response gains a `prescriptions` array prop; each element:
  `{ id, medication, dosage, frequency, duration, quantity, instructions, status, discontinued_at (ISO 8601 or null), discontinued_reason, provider_name (string or null), appointment_start_time (ISO 8601 or null), created_at (ISO 8601), creator_name }`, ordered newest-first.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PrescriptionTest.php`:

```php
    public function test_show_page_lists_the_patients_prescriptions_newest_first(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $older = Prescription::factory()->create(['patient_id' => $patient->id, 'created_at' => now()->subDay()]);
        $newer = Prescription::factory()->discontinued()->create(['patient_id' => $patient->id, 'created_at' => now()]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('prescriptions', 2)
            ->where('prescriptions.0.id', $newer->id)
            ->where('prescriptions.0.status', 'discontinued')
            ->where('prescriptions.1.id', $older->id)
            ->where('prescriptions.1.status', 'active')
        );
    }

    public function test_show_page_does_not_include_another_patients_prescriptions(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();
        Prescription::factory()->create(['patient_id' => $otherPatient->id]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('prescriptions', 0)
        );
    }

    public function test_show_page_prescription_shape(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create(['name' => 'Dr. Reyes']);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        Prescription::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'medication' => 'Amoxicillin',
            'created_by' => $user->id,
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertInertia(fn ($page) => $page
            ->where('prescriptions.0.medication', 'Amoxicillin')
            ->where('prescriptions.0.provider_name', 'Dr. Reyes')
            ->where('prescriptions.0.creator_name', $user->name)
            ->whereNot('prescriptions.0.appointment_start_time', null)
        );
    }

    public function test_no_delete_route_exists_for_prescriptions(): void
    {
        $this->assertFalse(Route::has('prescriptions.destroy'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: FAIL — `prescriptions` prop missing (`Inertia property [prescriptions] does not exist`).

- [ ] **Step 3: Add the prop**

Modify `app/Http/Controllers/Admin/PatientController.php`:

Add to the imports:
```php
use App\Models\Prescription;
```

In `show()`, add this entry to the `Inertia::render('Patients/Show', [...])` array, after `treatmentPlanItems`:

```php
            'prescriptions' => $patient->prescriptions()
                ->with(['provider', 'appointment', 'creator'])
                ->get()
                ->map(fn (Prescription $rx) => [
                    'id' => $rx->id,
                    'medication' => $rx->medication,
                    'dosage' => $rx->dosage,
                    'frequency' => $rx->frequency,
                    'duration' => $rx->duration,
                    'quantity' => $rx->quantity,
                    'instructions' => $rx->instructions,
                    'status' => $rx->status,
                    'discontinued_at' => $rx->discontinued_at?->toIso8601String(),
                    'discontinued_reason' => $rx->discontinued_reason,
                    'provider_name' => $rx->provider?->name,
                    'appointment_start_time' => $rx->appointment?->start_time?->toIso8601String(),
                    'created_at' => $rx->created_at->toIso8601String(),
                    'creator_name' => $rx->creator->name,
                ]),
```

- [ ] **Step 4: Run the full feature test file to verify it passes**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PrescriptionTest`
Expected: PASS (all prescription tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/PatientController.php tests/Feature/PrescriptionTest.php
git commit -m "Add PatientController prescriptions prop to Patients/Show"
```

---

## Task 5: Frontend — shared formatters, Prescriptions tab, wire-up, docs

**Files:**
- Create: `resources/js/Pages/Patients/format.js`
- Create: `resources/js/Pages/Patients/PrescriptionsTab.jsx`
- Modify: `resources/js/Pages/Patients/Show.jsx`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: `prescriptions` prop (Task 4), routes `prescriptions.store` / `prescriptions.update` (Tasks 2-3), existing `providers` and `appointments` props already passed to `Patients/Show`.
- Produces: no new backend interface. `format.js` exports `formatDate(iso)`, `formatDateTime(iso)`, `formatPeso(amount)` (moved verbatim from `Show.jsx`). `PrescriptionsTab` is the default export of `PrescriptionsTab.jsx`, props `{ patient, prescriptions, providers, appointments }`.

This task has no automated test (it is React view code; the data contract is already covered by Task 4's Inertia assertions). Verify with a production build and a manual smoke check.

- [ ] **Step 1: Create the shared formatter module**

Create `resources/js/Pages/Patients/format.js` — copy the three functions currently in `Show.jsx` verbatim, as named exports:

```js
export function formatDateTime(iso) {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function formatPeso(amount) {
    return `₱${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
```

- [ ] **Step 2: Point `Show.jsx` at the shared module**

Modify `resources/js/Pages/Patients/Show.jsx`:
- Add near the top, after the `@/Layouts/AuthenticatedLayout` import:
  ```js
  import { formatDate, formatDateTime, formatPeso } from './format';
  ```
- Delete the three local `function formatDateTime(...)`, `function formatDate(...)`, `function formatPeso(...)` definitions (currently around lines 7-23 and 67-69). Leave everything else — including all four existing tab bodies — untouched.

- [ ] **Step 3: Verify the existing page still builds and renders**

Run: `npm run build`
Expected: build succeeds, no "formatDate is not defined" type errors.

Then (dev server): `"$HOME/.config/herd/bin/php.bat" artisan serve` + `npm run dev`, open `/patients/{id}` for a patient with dental records / treatment items, click through the Overview, Dental Records, Dental Chart, and Treatment Plan tabs — dates and peso amounts still render correctly.

- [ ] **Step 4: Build the Prescriptions tab component**

Create `resources/js/Pages/Patients/PrescriptionsTab.jsx`:

```jsx
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { formatDate, formatDateTime } from './format';

function medicationLine(rx) {
    return [
        `${rx.medication} ${rx.dosage}`,
        rx.frequency,
        rx.duration,
        rx.quantity,
    ]
        .filter(Boolean)
        .join(' · ');
}

function PrescriptionCard({ rx, onDiscontinue }) {
    const discontinued = rx.status === 'discontinued';

    return (
        <div className={`bg-white shadow rounded p-4 text-sm ${discontinued ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className={`font-medium ${discontinued ? 'line-through' : ''}`}>
                        {medicationLine(rx)}
                    </div>
                    <div className="text-gray-500">
                        {rx.provider_name || '—'}
                        {rx.appointment_start_time && ` · linked to ${formatDateTime(rx.appointment_start_time)}`}
                    </div>
                </div>
                {!discontinued && (
                    <button type="button" onClick={onDiscontinue} className="text-sm text-blue-600 shrink-0">
                        Discontinue
                    </button>
                )}
            </div>
            {rx.instructions && <p className="mt-2">{rx.instructions}</p>}
            {discontinued && (
                <p className="mt-2 text-gray-600">
                    Discontinued on {formatDate(rx.discontinued_at)}
                    {rx.discontinued_reason && ` — ${rx.discontinued_reason}`}
                </p>
            )}
            <div className="mt-3 text-xs text-gray-400">
                Prescribed by {rx.creator_name} on {formatDate(rx.created_at)}
            </div>
        </div>
    );
}

export default function PrescriptionsTab({ patient, prescriptions, providers, appointments }) {
    const [showNewModal, setShowNewModal] = useState(false);
    const [discontinuingRx, setDiscontinuingRx] = useState(null);

    const newForm = useForm({
        medication: '',
        dosage: '',
        frequency: '',
        duration: '',
        quantity: '',
        provider_id: '',
        appointment_id: '',
        instructions: '',
    });

    const discontinueForm = useForm({
        discontinued_reason: '',
    });

    function openNew() {
        newForm.reset();
        newForm.clearErrors();
        setShowNewModal(true);
    }

    function submitNew(e) {
        e.preventDefault();
        newForm.post(route('prescriptions.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                newForm.reset();
                setShowNewModal(false);
            },
        });
    }

    function openDiscontinue(rx) {
        discontinueForm.reset();
        discontinueForm.clearErrors();
        setDiscontinuingRx(rx);
    }

    function submitDiscontinue(e) {
        e.preventDefault();
        discontinueForm.patch(route('prescriptions.update', { patient: patient.id, prescription: discontinuingRx.id }), {
            preserveScroll: true,
            onSuccess: () => {
                discontinueForm.reset();
                setDiscontinuingRx(null);
            },
        });
    }

    const active = prescriptions.filter((rx) => rx.status === 'active');
    const discontinued = prescriptions.filter((rx) => rx.status === 'discontinued');

    return (
        <div>
            <button
                type="button"
                onClick={openNew}
                className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
            >
                + New Prescription
            </button>

            <div className="space-y-6">
                <div>
                    <h4 className="mb-2 text-sm font-semibold text-gray-500">Active</h4>
                    <div className="space-y-3">
                        {active.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} onDiscontinue={() => openDiscontinue(rx)} />
                        ))}
                        {active.length === 0 && (
                            <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                No active prescriptions.
                            </div>
                        )}
                    </div>
                </div>
                <div>
                    <h4 className="mb-2 text-sm font-semibold text-gray-500">Discontinued</h4>
                    <div className="space-y-3">
                        {discontinued.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} onDiscontinue={() => {}} />
                        ))}
                        {discontinued.length === 0 && (
                            <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                No discontinued prescriptions.
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {showNewModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitNew} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">New prescription</h3>

                        <div>
                            <label className="block text-sm mb-1">Medication</label>
                            <input
                                type="text"
                                className="w-full border rounded px-3 py-2"
                                value={newForm.data.medication}
                                onChange={(e) => newForm.setData('medication', e.target.value)}
                            />
                            {newForm.errors.medication && <p className="text-sm text-red-600">{newForm.errors.medication}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Dosage</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 500 mg"
                                    value={newForm.data.dosage}
                                    onChange={(e) => newForm.setData('dosage', e.target.value)}
                                />
                                {newForm.errors.dosage && <p className="text-sm text-red-600">{newForm.errors.dosage}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Frequency</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 3 times daily"
                                    value={newForm.data.frequency}
                                    onChange={(e) => newForm.setData('frequency', e.target.value)}
                                />
                                {newForm.errors.frequency && <p className="text-sm text-red-600">{newForm.errors.frequency}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Duration (optional)</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 7 days"
                                    value={newForm.data.duration}
                                    onChange={(e) => newForm.setData('duration', e.target.value)}
                                />
                                {newForm.errors.duration && <p className="text-sm text-red-600">{newForm.errors.duration}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Quantity (optional)</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 21 capsules"
                                    value={newForm.data.quantity}
                                    onChange={(e) => newForm.setData('quantity', e.target.value)}
                                />
                                {newForm.errors.quantity && <p className="text-sm text-red-600">{newForm.errors.quantity}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Provider</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={newForm.data.provider_id}
                                    onChange={(e) => newForm.setData('provider_id', e.target.value)}
                                >
                                    <option value="">No provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                {newForm.errors.provider_id && <p className="text-sm text-red-600">{newForm.errors.provider_id}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Link to appointment (optional)</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={newForm.data.appointment_id}
                                    onChange={(e) => newForm.setData('appointment_id', e.target.value)}
                                >
                                    <option value="">No linked appointment</option>
                                    {appointments.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                        </option>
                                    ))}
                                </select>
                                {newForm.errors.appointment_id && <p className="text-sm text-red-600">{newForm.errors.appointment_id}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Instructions (optional)</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={newForm.data.instructions}
                                onChange={(e) => newForm.setData('instructions', e.target.value)}
                            />
                            {newForm.errors.instructions && <p className="text-sm text-red-600">{newForm.errors.instructions}</p>}
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { newForm.clearErrors(); setShowNewModal(false); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={newForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {discontinuingRx && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitDiscontinue} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Discontinue: {discontinuingRx.medication}</h3>
                        <p className="text-sm text-gray-600">
                            The prescription stays on the record — this marks it no longer active.
                        </p>

                        <div>
                            <label className="block text-sm mb-1">Reason (optional)</label>
                            <input
                                type="text"
                                className="w-full border rounded px-3 py-2"
                                value={discontinueForm.data.discontinued_reason}
                                onChange={(e) => discontinueForm.setData('discontinued_reason', e.target.value)}
                            />
                            {discontinueForm.errors.discontinued_reason && (
                                <p className="text-sm text-red-600">{discontinueForm.errors.discontinued_reason}</p>
                            )}
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { discontinueForm.clearErrors(); setDiscontinuingRx(null); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={discontinueForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Discontinue
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
```

- [ ] **Step 5: Wire the tab into `Show.jsx`**

Modify `resources/js/Pages/Patients/Show.jsx`:

- Add the import after the `./format` import:
  ```js
  import PrescriptionsTab from './PrescriptionsTab';
  ```
- Add `prescriptions` to the destructured props of `export default function Show({ ... })`:
  ```js
  export default function Show({ patient, dentalRecords, toothConditions, treatmentPlanItems, prescriptions, providers, appointments }) {
  ```
- Add a fifth tab button after the "Treatment Plan" button, copying that button's markup exactly, with `tab === 'prescriptions'` and label `Prescriptions`:
  ```jsx
  <button
      type="button"
      onClick={() => setTab('prescriptions')}
      className={`pb-2 text-sm font-medium ${tab === 'prescriptions' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
  >
      Prescriptions
  </button>
  ```
- After the `{tab === 'treatment' && ( ... )}` block (closes around line 454), add:
  ```jsx
  {tab === 'prescriptions' && (
      <PrescriptionsTab
          patient={patient}
          prescriptions={prescriptions}
          providers={providers}
          appointments={appointments}
      />
  )}
  ```

- [ ] **Step 6: Build and smoke-test**

Run: `npm run build`
Expected: succeeds with no errors.

Dev smoke check (`artisan serve` + `npm run dev`), on `/patients/{id}`:
1. Click the **Prescriptions** tab → see "Active" / "Discontinued" headings, both empty states.
2. **+ New Prescription** → submit with medication/dosage/frequency blank → three inline errors, modal stays open.
3. Fill medication "Amoxicillin", dosage "500 mg", frequency "3 times daily", duration "7 days", pick a provider, add instructions → Save → card appears under Active, showing "Amoxicillin 500 mg · 3 times daily · 7 days", provider name, "Prescribed by <you> on <today>".
4. **Discontinue** on that card → modal shows "Discontinue: Amoxicillin" → add reason "Course completed" → Discontinue → card moves to Discontinued, struck through, "Discontinued on <today> — Course completed", no Discontinue button.
5. Reload the page → state persists, tab starts back on Overview.
6. Click through the other four tabs → still fine.

- [ ] **Step 7: Update `CLAUDE.md`**

Modify `CLAUDE.md` — under "Planning workflow" → "Shipped so far", after the "Phase 6, sub-project 3" bullet, add:

```markdown
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
```

- [ ] **Step 8: Run the full test suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all tests pass (the pre-existing 205 + the new `PrescriptionTest` cases).

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Patients/format.js resources/js/Pages/Patients/PrescriptionsTab.jsx resources/js/Pages/Patients/Show.jsx CLAUDE.md
git commit -m "Add the Prescriptions tab with discontinue action"
```

---

## Self-Review

**1. Spec coverage:**

| Spec section | Task |
|---|---|
| `prescriptions` table (all columns, FK behaviours) | Task 1 |
| `Prescription` model — `STATUSES`, `$fillable` excludes status/discontinued_*/created_by, `discontinued_at` cast, 4 relations | Task 1 |
| `Patient::prescriptions()` newest-first | Task 1 |
| `PrescriptionFactory` + `discontinued()` state | Task 1 |
| `store` validation (required medication/dosage/frequency; nullable duration/quantity/instructions; provider exists; appointment belongs to patient) | Task 2 |
| `store` sets `created_by` server-side, `status` defaults active | Task 2 |
| `prescriptions.store` route | Task 2 |
| `update` = one-way discontinue: 404 cross-patient, 403 if not active, validates only `discontinued_reason`, ignores drug fields, sets status/discontinued_at/reason | Task 3 |
| `prescriptions.update` route | Task 3 |
| `PatientController::show()` `prescriptions` prop (exact shape, ISO dates, provider_name/creator_name) | Task 4 |
| No `destroy` route (asserted) | Task 4 |
| `format.js` extraction, `Show.jsx` import swap, other tabs untouched | Task 5 steps 1-3 |
| `PrescriptionsTab.jsx` — New Prescription modal, Active/Discontinued groups, per-card Discontinue, muted+struck discontinued cards, empty states | Task 5 step 4 |
| 5th tab button + render in `Show.jsx` | Task 5 step 5 |
| Discontinue modal — medication in heading, optional reason, non-destructive styling | Task 5 step 4 |
| `CLAUDE.md` "Phase 6 sub-project 4" bullet | Task 5 step 7 |
| Full suite green | Task 5 step 8 |

All spec "Out of scope" items (print/PDF, drug DB, interaction checks, refills, e-prescribing, portal, content edit/delete, filtering) are absent from every task — correct.

**2. Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N"/"add validation" — every code step has literal code. Task 5's manual-check steps enumerate concrete click paths, not "test it".

**3. Type consistency:**
- `Prescription::STATUSES` defined Task 1, not otherwise referenced in code (controller compares to the literal `'active'` string, matching how `TreatmentPlanItemController` checks `patient_id`). Consistent with repo style; `STATUSES` still earns its place for a future `Rule::in` and documents the domain.
- `store` uses `$patient->prescriptions()->make($validated)` then direct `created_by` assignment + `save()` — matches Task 1's relation and the `TreatmentPlanItemController` pattern.
- `update` uses direct property assignment for the three non-fillable columns (Step 3 note corrects the initial `->update([...])` draft) — consistent with the `$fillable` list in Task 1 that deliberately omits them.
- Prop shape in Task 4 (`provider_name`, `appointment_start_time`, `creator_name`, `discontinued_at` ISO) exactly matches the keys `PrescriptionsTab.jsx` reads in Task 5 (`rx.provider_name`, `rx.appointment_start_time`, `rx.creator_name`, `rx.discontinued_at`).
- Route param name `prescription` (Task 3) matches `route('prescriptions.update', { patient: ..., prescription: ... })` in Task 5.
- `formatDate`/`formatDateTime`/`formatPeso` names identical across `format.js`, `Show.jsx` import, and `PrescriptionsTab.jsx` import.

No issues found.
