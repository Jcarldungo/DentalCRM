# Patient Detail Page + Dental Records Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each patient a detail page (`/patients/{patient}`) with an Overview tab (existing profile fields) and a Dental Records tab (an append-only chronological clinical-note history), replacing the current flat table-only patient management.

**Architecture:** One new table (`dental_records`) and model, with `patient_id` cascading on delete and `provider_id`/`appointment_id` nullable and null-on-delete. A new `Admin\DentalRecordController` with `store()` only — no update/destroy anywhere in this feature, by design. `PatientController` gains a `show()` method. One new Inertia page, `Patients/Show.jsx`; `Patients/Index.jsx` gets a link into it.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit (via `php artisan test`), MariaDB (`dentalcrm_testing`).

**Spec:** [`docs/superpowers/specs/2026-08-26-dental-records-design.md`](../specs/2026-08-26-dental-records-design.md)

## Global Constraints

- No RBAC — every authenticated user can create and view dental records, same as every other staff feature.
- No odontogram, treatment plans, prescriptions, or attachments in this slice — `DentalRecord` stays a plain clinical-note shape.
- No edit or delete of a `DentalRecord`, ever — no route, no controller method, no UI button, by design (not a gap to fill later).
- `patient_id`: required, `cascadeOnDelete()`. `provider_id` and `appointment_id`: nullable, `nullOnDelete()`.
- `created_by` is set server-side only from `$request->user()->id` and is excluded from `DentalRecord::$fillable` — never trust a client-supplied value.
- A dental record submission must have at least one of `examination`/`diagnosis`/`procedure`/`notes` non-empty after `trim()`.
- `Patient::dentalRecords()` orders newest-first at the relationship definition (`->latest('created_at')`), not in the controller or the UI.
- An `appointment_id` must belong to the same patient the record is being created for — validated against the route-bound `Patient`, not just checked for existence.
- Run PHP commands with `"/c/Users/Jann Carl/.config/herd/bin/php.bat"` (verified path on this machine — the `JC` path in `CLAUDE.md` is stale) from the repo root, `C:\dev\dentalcrm`.

---

## Task 1: `dental_records` table, `DentalRecord` model, and `Patient::dentalRecords()`

**Files:**
- Create: `database/migrations/2026_08_26_090000_create_dental_records_table.php`
- Create: `app/Models/DentalRecord.php`
- Create: `database/factories/DentalRecordFactory.php`
- Modify: `app/Models/Patient.php`
- Test: `tests/Feature/DentalRecordTest.php` (new file)

**Interfaces:**
- Consumes: `Patient`, `Provider`, `Appointment`, `User` models (all existing, unmodified) and their factories.
- Produces: `DentalRecord` model with `const TYPES = ['consultation', 'procedure', 'follow_up', 'other']`, `const UPDATED_AT = null`, fillable `['patient_id', 'provider_id', 'appointment_id', 'type', 'examination', 'diagnosis', 'procedure', 'notes']`, and relations `patient()`, `provider()`, `appointment()`, `creator()` (all `BelongsTo`). `DentalRecordFactory` for test setup. `Patient::dentalRecords(): HasMany`, ordered newest-first — every later task (the controller, the tests) relies on this ordering already being correct at the relationship level.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DentalRecordTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DentalRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_dental_record_belongs_to_patient_provider_appointment_and_creator(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $record = DentalRecord::create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'type' => 'consultation',
            'notes' => 'Test note',
            'created_by' => $user->id,
        ]);

        $this->assertSame($patient->id, $record->patient->id);
        $this->assertSame($provider->id, $record->provider->id);
        $this->assertSame($appointment->id, $record->appointment->id);
        $this->assertSame($user->id, $record->creator->id);
        $this->assertNull($record->updated_at);
    }

    public function test_patient_dental_records_relation_orders_newest_first(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $older = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $ordered = $patient->dentalRecords;

        $this->assertSame($newer->id, $ordered->first()->id);
        $this->assertSame($older->id, $ordered->last()->id);
    }

    public function test_deleting_a_patient_cascades_to_their_dental_records(): void
    {
        $patient = Patient::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->assertDatabaseMissing('dental_records', ['id' => $record->id]);
    }

    public function test_deleting_a_provider_nulls_the_dental_records_provider_reference(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'provider_id' => $provider->id,
            'created_by' => $user->id,
        ]);

        $provider->delete();

        $this->assertNull($record->fresh()->provider_id);
    }

    public function test_deleting_an_appointment_nulls_the_dental_records_appointment_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $user = User::factory()->create();
        $record = DentalRecord::factory()->create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'created_by' => $user->id,
        ]);

        $appointment->delete();

        $this->assertNull($record->fresh()->appointment_id);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=DentalRecordTest`
Expected: FAIL — `Class "App\Models\DentalRecord" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_26_090000_create_dental_records_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('examination')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('procedure')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_records');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/DentalRecord.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DentalRecord extends Model
{
    use HasFactory;

    public const TYPES = ['consultation', 'procedure', 'follow_up', 'other'];

    /**
     * Append-only: there is no updated_at column, and this tells Eloquent
     * to never try to write one.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id',
        'provider_id',
        'appointment_id',
        'type',
        'examination',
        'diagnosis',
        'procedure',
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

Create `database/factories/DentalRecordFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\DentalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentalRecord>
 */
class DentalRecordFactory extends Factory
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
            'type' => $this->faker->randomElement(DentalRecord::TYPES),
            'examination' => null,
            'diagnosis' => null,
            'procedure' => null,
            'notes' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `Patient`**

In `app/Models/Patient.php`, add this method (after the existing `appointments()` method):

```php
    public function dentalRecords(): HasMany
    {
        return $this->hasMany(DentalRecord::class)->latest('created_at');
    }
```

`HasMany` is already imported at the top of this file (used by `appointments()`), so no new import is needed.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=DentalRecordTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_26_090000_create_dental_records_table.php app/Models/DentalRecord.php database/factories/DentalRecordFactory.php app/Models/Patient.php tests/Feature/DentalRecordTest.php
git commit -m "Add dental_records table, DentalRecord model, and Patient::dentalRecords()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `PatientController::show()` and `DentalRecordController::store()`

**Files:**
- Create: `app/Http/Controllers/Admin/DentalRecordController.php`
- Modify: `app/Http/Controllers/Admin/PatientController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DentalRecordTest.php`

**Interfaces:**
- Consumes: `DentalRecord` model, `Patient::dentalRecords()` (Task 1). Route-naming/middleware convention from the existing `auth` group in `routes/web.php`.
- Produces: `GET /patients/{patient}` (name `patients.show`) → Inertia component `'Patients/Show'` with props `patient` (the full `Patient` model), `dentalRecords` (array of `{ id, type, provider_name, appointment_start_time, examination, diagnosis, procedure, notes, created_at, creator_name }`, newest first), `providers` (array of `{ id, name }`), `appointments` (this patient's own appointments, array of `{ id, start_time, type }`, newest first). `POST /patients/{patient}/dental-records` (name `dental-records.store`) — Task 3 (frontend) submits `type`, `provider_id`, `appointment_id`, `examination`, `diagnosis`, `procedure`, `notes` to this route; a validation failure on missing clinical content lands in `errors.clinical_content`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DentalRecordTest.php` (inside the `DentalRecordTest` class, before its closing `}`; add `use Illuminate\Support\Facades\Route;` to the file's `use` block at the top):

```php
    public function test_guest_cannot_view_a_patients_detail_page(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->get(route('patients.show', $patient));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_create_a_dental_record(): void
    {
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Test note',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_dental_record_can_be_created_with_only_notes(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Patient reports mild sensitivity.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DentalRecord::count());
        $record = DentalRecord::first();
        $this->assertSame($patient->id, $record->patient_id);
        $this->assertSame('consultation', $record->type);
        $this->assertSame('Patient reports mild sensitivity.', $record->notes);
        $this->assertNull($record->provider_id);
        $this->assertNull($record->appointment_id);
        $this->assertSame($user->id, $record->created_by);
    }

    public function test_created_by_is_always_the_authenticated_user_even_if_the_request_supplies_a_different_value(): void
    {
        $user = $this->actingUser();
        $otherUser = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'notes' => 'Note',
            'created_by' => $otherUser->id,
        ]);

        $this->assertSame($user->id, DentalRecord::first()->created_by);
    }

    public function test_a_dental_record_can_be_created_with_a_provider_and_appointment(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'procedure',
            'provider_id' => $provider->id,
            'appointment_id' => $appointment->id,
            'procedure' => 'Composite filling, tooth #14.',
        ]);

        $response->assertRedirect();
        $record = DentalRecord::first();
        $this->assertSame($provider->id, $record->provider_id);
        $this->assertSame($appointment->id, $record->appointment_id);
    }

    public function test_an_appointment_belonging_to_a_different_patient_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatientsAppointment = Appointment::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'appointment_id' => $otherPatientsAppointment->id,
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('appointment_id');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_nonexistent_provider_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'provider_id' => 999999,
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_an_invalid_type_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'not-a-real-type',
            'notes' => 'Note',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_no_clinical_content_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
        ]);

        $response->assertSessionHasErrors('clinical_content');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_only_whitespace_clinical_content_is_rejected(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'examination' => '   ',
            'diagnosis' => "\n\t",
            'procedure' => '',
            'notes' => '   ',
        ]);

        $response->assertSessionHasErrors('clinical_content');
        $this->assertSame(0, DentalRecord::count());
    }

    public function test_a_submission_with_only_examination_populated_succeeds(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create();

        $response = $this->post(route('dental-records.store', $patient), [
            'type' => 'consultation',
            'examination' => 'No visible decay.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DentalRecord::count());
    }

    public function test_patients_show_page_returns_this_patients_dental_records_newest_first_and_not_another_patients(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        DentalRecord::factory()->create([
            'patient_id' => $otherPatient->id,
            'created_by' => $user->id,
        ]);
        $older = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = DentalRecord::factory()->create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->get(route('patients.show', $patient));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Patients/Show')
            ->has('dentalRecords', 2)
            ->where('dentalRecords.0.id', $newer->id)
            ->where('dentalRecords.1.id', $older->id)
        );
    }

    public function test_no_edit_or_delete_routes_exist_for_dental_records(): void
    {
        $this->assertFalse(Route::has('dental-records.update'));
        $this->assertFalse(Route::has('dental-records.destroy'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=DentalRecordTest`
Expected: FAIL — `patients.show` and `dental-records.store` routes don't exist (`RouteNotFoundException`).

- [ ] **Step 3: Create `DentalRecordController`**

Create `app/Http/Controllers/Admin/DentalRecordController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DentalRecord;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DentalRecordController extends Controller
{
    /**
     * Append-only: there is deliberately no update()/destroy() here, no
     * matching routes, and no UI to reach them. A correction is a new
     * record, not an edit to this one.
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(DentalRecord::TYPES)],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'examination' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'procedure' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $hasClinicalContent = collect(['examination', 'diagnosis', 'procedure', 'notes'])
                ->contains(fn (string $field) => trim((string) $request->input($field)) !== '');

            if (! $hasClinicalContent) {
                $validator->errors()->add(
                    'clinical_content',
                    'Enter at least one of examination, diagnosis, procedure, or notes.'
                );
            }
        });

        $validated = $validator->validate();

        // created_by is never trusted from the request — set explicitly
        // from the authenticated user, and it isn't in $fillable either.
        $patient->dentalRecords()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return back();
    }
}
```

- [ ] **Step 4: Add `show()` to `PatientController`**

In `app/Http/Controllers/Admin/PatientController.php`, add these two imports alongside the existing ones:

```php
use App\Models\DentalRecord;
use App\Models\Provider;
```

Then add this method (after `index()`):

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

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import alongside the other `Admin` controller imports:

```php
use App\Http\Controllers\Admin\DentalRecordController;
```

Change:

```php
    Route::resource('patients', PatientController::class)
        ->except(['create', 'edit', 'show']);
```

to:

```php
    Route::resource('patients', PatientController::class)
        ->except(['create', 'edit']);

    Route::post('/patients/{patient}/dental-records', [DentalRecordController::class, 'store'])
        ->name('dental-records.store');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=DentalRecordTest`
Expected: PASS (16 tests total: 5 from Task 1 + 11 new).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/DentalRecordController.php app/Http/Controllers/Admin/PatientController.php routes/web.php tests/Feature/DentalRecordTest.php
git commit -m "Add PatientController::show() and DentalRecordController::store()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `Patients/Show.jsx` — Overview and Dental Records tabs, linked from the patients list

**Files:**
- Modify: `resources/js/Pages/Patients/Index.jsx`
- Create: `resources/js/Pages/Patients/Show.jsx`

**Interfaces:**
- Consumes: Inertia props `patient`, `dentalRecords`, `providers`, `appointments` (Task 2's exact shape); `route('patients.update', id)` (existing) for the Overview tab's edit modal; `route('dental-records.store', patientId)` (Task 2) for the New Record modal; `route('patients.show', id)` (Task 2) as the link target from the index.
- Produces: the `/patients/{patient}` page, reachable by clicking a patient's name in `Patients/Index.jsx`.

- [ ] **Step 1: Link each patient row to its detail page**

In `resources/js/Pages/Patients/Index.jsx`, change the import line:

```jsx
import { Head, useForm, router } from '@inertiajs/react';
```

to:

```jsx
import { Head, Link, useForm, router } from '@inertiajs/react';
```

Then change the row markup:

```jsx
                        <div key={patient.id} className="flex items-center justify-between p-4">
                            <div>
                                <div className="font-medium">{patient.first_name} {patient.last_name}</div>
                                <div className="text-sm text-gray-500">{patient.phone ?? patient.email ?? '—'}</div>
                            </div>
                            <div className="flex items-center gap-3">
                                <button onClick={() => openEdit(patient)} className="text-sm text-blue-600">Edit</button>
                                <button onClick={() => destroy(patient)} className="text-sm text-red-600">Delete</button>
                            </div>
                        </div>
```

to:

```jsx
                        <div key={patient.id} className="flex items-center justify-between p-4">
                            <Link href={route('patients.show', patient.id)} className="hover:underline">
                                <div className="font-medium">{patient.first_name} {patient.last_name}</div>
                                <div className="text-sm text-gray-500">{patient.phone ?? patient.email ?? '—'}</div>
                            </Link>
                            <div className="flex items-center gap-3">
                                <button onClick={() => openEdit(patient)} className="text-sm text-blue-600">Edit</button>
                                <button onClick={() => destroy(patient)} className="text-sm text-red-600">Delete</button>
                            </div>
                        </div>
```

- [ ] **Step 2: Create the Show page**

Create `resources/js/Pages/Patients/Show.jsx`:

```jsx
import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['consultation', 'procedure', 'follow_up', 'other'];

function formatDateTime(iso) {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function Show({ patient, dentalRecords, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);

    const patientForm = useForm({
        first_name: patient.first_name,
        last_name: patient.last_name,
        date_of_birth: patient.date_of_birth ?? '',
        phone: patient.phone ?? '',
        email: patient.email ?? '',
        emergency_contact_name: patient.emergency_contact_name ?? '',
        emergency_contact_phone: patient.emergency_contact_phone ?? '',
        notes: patient.notes ?? '',
        recall_interval_months: patient.recall_interval_months ?? '',
    });

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
        e.preventDefault();
        patientForm.put(route('patients.update', patient.id), {
            onSuccess: () => setShowEditModal(false),
        });
    }

    function submitRecord(e) {
        e.preventDefault();
        recordForm.post(route('dental-records.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                recordForm.reset();
                setShowRecordModal(false);
            },
        });
    }

    const patientField = (label, name, type = 'text') => (
        <div>
            <label className="block text-sm mb-1">{label}</label>
            <input
                type={type}
                className="w-full border rounded px-3 py-2"
                value={patientForm.data[name]}
                onChange={(e) => patientForm.setData(name, e.target.value)}
            />
            {patientForm.errors[name] && <p className="text-sm text-red-600">{patientForm.errors[name]}</p>}
        </div>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{patient.first_name} {patient.last_name}</h2>}>
            <Head title={`${patient.first_name} ${patient.last_name}`} />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-6 flex gap-6 border-b">
                    <button
                        type="button"
                        onClick={() => setTab('overview')}
                        className={`pb-2 text-sm font-medium ${tab === 'overview' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Overview
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('records')}
                        className={`pb-2 text-sm font-medium ${tab === 'records' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Records
                    </button>
                </div>

                {tab === 'overview' && (
                    <div className="bg-white shadow rounded p-6">
                        <div className="mb-4 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setShowEditModal(true)}
                                className="text-sm text-blue-600"
                            >
                                Edit
                            </button>
                        </div>
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-gray-500">Date of birth</dt>
                                <dd>{patient.date_of_birth ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Phone</dt>
                                <dd>{patient.phone ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Email</dt>
                                <dd>{patient.email ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Recall interval (months)</dt>
                                <dd>{patient.recall_interval_months ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Emergency contact</dt>
                                <dd>{patient.emergency_contact_name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Emergency contact phone</dt>
                                <dd>{patient.emergency_contact_phone ?? '—'}</dd>
                            </div>
                        </dl>
                        {patient.notes && (
                            <div className="mt-4">
                                <dt className="text-sm text-gray-500">Notes</dt>
                                <dd className="text-sm">{patient.notes}</dd>
                            </div>
                        )}
                    </div>
                )}

                {tab === 'records' && (
                    <div>
                        <button
                            type="button"
                            onClick={() => setShowRecordModal(true)}
                            className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
                        >
                            + New Record
                        </button>

                        <div className="space-y-3">
                            {dentalRecords.map((record) => (
                                <div key={record.id} className="bg-white shadow rounded p-4 text-sm">
                                    <div className="text-gray-500">
                                        {record.type.replace('_', ' ')}
                                        {record.provider_name && ` · ${record.provider_name}`}
                                        {record.appointment_start_time && ` · linked to ${formatDateTime(record.appointment_start_time)}`}
                                    </div>
                                    {record.examination && <p className="mt-2"><strong>Examination:</strong> {record.examination}</p>}
                                    {record.diagnosis && <p className="mt-2"><strong>Diagnosis:</strong> {record.diagnosis}</p>}
                                    {record.procedure && <p className="mt-2"><strong>Procedure:</strong> {record.procedure}</p>}
                                    {record.notes && <p className="mt-2"><strong>Notes:</strong> {record.notes}</p>}
                                    <div className="mt-3 text-xs text-gray-400">
                                        Logged by {record.creator_name} on {formatDate(record.created_at)}
                                    </div>
                                </div>
                            ))}
                            {dentalRecords.length === 0 && (
                                <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                    No dental records yet.
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {showEditModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitPatientEdit} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Edit patient</h3>
                        <div className="grid grid-cols-2 gap-4">
                            {patientField('First name', 'first_name')}
                            {patientField('Last name', 'last_name')}
                            {patientField('Date of birth', 'date_of_birth', 'date')}
                            {patientField('Phone', 'phone')}
                            {patientField('Email', 'email', 'email')}
                            {patientField('Recall interval (months)', 'recall_interval_months', 'number')}
                            {patientField('Emergency contact name', 'emergency_contact_name')}
                            {patientField('Emergency contact phone', 'emergency_contact_phone')}
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={3}
                                value={patientForm.data.notes}
                                onChange={(e) => patientForm.setData('notes', e.target.value)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowEditModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={patientForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {showRecordModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitRecord} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">New dental record</h3>

                        {recordForm.errors.clinical_content && (
                            <p className="text-sm text-red-600">{recordForm.errors.clinical_content}</p>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Type</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={recordForm.data.type}
                                    onChange={(e) => recordForm.setData('type', e.target.value)}
                                >
                                    {TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Provider</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={recordForm.data.provider_id}
                                    onChange={(e) => recordForm.setData('provider_id', e.target.value)}
                                >
                                    <option value="">No provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                {recordForm.errors.provider_id && <p className="text-sm text-red-600">{recordForm.errors.provider_id}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Link to appointment (optional)</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={recordForm.data.appointment_id}
                                onChange={(e) => recordForm.setData('appointment_id', e.target.value)}
                            >
                                <option value="">No linked appointment</option>
                                {appointments.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                    </option>
                                ))}
                            </select>
                            {recordForm.errors.appointment_id && <p className="text-sm text-red-600">{recordForm.errors.appointment_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Examination</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.examination}
                                onChange={(e) => recordForm.setData('examination', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Diagnosis</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.diagnosis}
                                onChange={(e) => recordForm.setData('diagnosis', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Procedure</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.procedure}
                                onChange={(e) => recordForm.setData('procedure', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.notes}
                                onChange={(e) => recordForm.setData('notes', e.target.value)}
                            />
                        </div>

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

- [ ] **Step 3: Build the frontend to catch syntax/import errors**

Run: `npm run build`
Expected: build succeeds with no errors (this codebase has no JS test runner — a clean build is the existing verification step for frontend-only changes).

- [ ] **Step 4: Run the full backend test suite**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test`
Expected: PASS, all tests (the full existing suite plus all 16 `DentalRecordTest` tests) — zero regressions.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Patients/Index.jsx resources/js/Pages/Patients/Show.jsx
git commit -m "Add the patient detail page with Overview and Dental Records tabs

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Final verification

After Task 3, manually confirm (via `composer run dev` or `php artisan serve` + `npm run dev`) the flows the spec calls out:

1. From `/patients`, click a patient's name → lands on `/patients/{id}` on the Overview tab, showing their existing profile fields.
2. Click Edit on Overview → the same edit modal as the patients list → Save → changes reflected.
3. Switch to the Dental Records tab → click "+ New Record" → fill in only Notes → Save → record appears at the top of the list with "Logged by \<your name\> on \<today's date\>".
4. Add a second record with Examination and a linked appointment (if the patient has one) → appears above the first (newest-first).
5. Try submitting the New Record form with every clinical field blank → rejected with the "Enter at least one of..." error, no modal-close.
6. Confirm there is no Edit or Delete control anywhere on a dental record.

All of these are already covered by `DentalRecordTest.php`; this step is a manual sanity check of the UI wiring, not a substitute for the automated tests.
