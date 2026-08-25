# DentalCRM Phase 3: Public Appointment Requests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let guests submit an appointment request from the public site (service, optional dentist preference, preferred date and time-of-day, contact details), and let staff confirm or decline those requests from the existing internal Appointments page — while closing two scheduling-integrity holes that confirmation would otherwise walk into.

**Architecture:** No new tables and no new staff nav section. A request is an `Appointment` row with `status = 'requested'` and no real time yet: `start_time`, `end_time`, `provider_id`, and `type` become nullable, and five new nullable columns carry what the guest actually told us. A new public `BookingController@store` creates the request (matching or creating a `Patient` by normalized email); staff confirm via the *existing* `PATCH /appointments/{id}`, which now enforces that a `scheduled` appointment is a complete one, and that no two appointments overlap for the same provider.

**Tech Stack:** Laravel 12, Inertia 2 + React 18 (existing), Tailwind CSS 3 (existing), `lucide-react` (existing), PHPUnit via `php artisan test` (existing).

**Spec:** `docs/superpowers/specs/2026-08-25-appointment-booking-design.md`

## Global Constraints

- No new backend or frontend packages. Everything here uses what's already installed.
- **PHP and Composer are not on PATH.** Use `"/c/Users/JC/.config/herd/bin/php.bat"` in place of `php` for every command in this plan (see `CLAUDE.md`). Written as `php` below for readability.
- No email or SMS sending anywhere. Declining or confirming a request notifies nobody; staff follow up out-of-band, exactly as with Phase 2's inquiries.
- No patient accounts, no guest cancellation or rescheduling, no live per-slot availability, no holiday/break awareness.
- `provider_id` is **always `NULL`** on a request. The guest's dentist choice is free text in `dentist_preference` — public dentist profiles are static Phase 2 content, not `providers` rows.
- Tests follow the existing flat convention: `tests/Feature/<Name>Test.php`, run via `php artisan test`, against the MariaDB `dentalcrm_testing` database already configured in `phpunit.xml`.
- Staff-facing controllers live in `App\Http\Controllers\Admin\`; public ones at the top level.
- Clean-codebase rules apply throughout: no `dd()`/`console.log`/`var_dump`, no unused imports, no commented-out code.
- Public-site styling stays in the teal/stone palette established in Phase 2; the internal app keeps its existing look. Do not let either leak into the other.
- The clinic identity stays fictional ("Harborview Dental Clinic", `.example` email domain). Prices use `₱`.
- Guest-facing copy must never imply an appointment is confirmed. The success wording is "Appointment request submitted", never "Appointment confirmed".

---

### Task 1: Appointment schema, model, and factory changes

**Files:**
- Create: `database/migrations/xxxx_add_request_fields_to_appointments_table.php`
- Create: `config/clinic.php`
- Modify: `app/Models/Appointment.php`
- Modify: `database/factories/AppointmentFactory.php`
- Test: `tests/Feature/AppointmentTest.php` (add one test)

**Interfaces:**
- Produces: `Appointment::STATUSES` (now 6 values incl. `requested`, `declined`), `Appointment::TIMES_OF_DAY` (`['morning', 'afternoon']`), `Appointment::SLOT_FREEING_STATUSES` (`['cancelled', 'declined', 'no_show']`), `Appointment::hasConflict(int $providerId, Carbon $start, Carbon $end, ?int $ignoreId = null): bool`. New fillable/cast columns `service_interest`, `dentist_preference`, `preferred_date`, `preferred_time_of_day`, `notes`. `AppointmentFactory::requested()` state. `config('clinic.closed_days')` returning an array of Carbon weekday integers.
- Consumed by: Task 2 (guards use `hasConflict`, `SLOT_FREEING_STATUSES`, `STATUSES`), Task 3 (`TIMES_OF_DAY`, `closed_days`, the new columns), Task 6 (`requested` status, the new columns).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/AppointmentTest.php`:

```php
    public function test_a_requested_appointment_can_be_stored_without_a_time_or_provider(): void
    {
        $patient = Patient::factory()->create();

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => '2026-09-02',
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'requested',
            'start_time' => null,
            'end_time' => null,
            'provider_id' => null,
            'type' => null,
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_time_of_day' => 'morning',
        ]);
        $this->assertSame('2026-09-02', $appointment->fresh()->preferred_date->toDateString());
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test tests/Feature/AppointmentTest.php --filter=test_a_requested_appointment_can_be_stored_without_a_time_or_provider
```

Expected: FAIL — the `service_interest` column doesn't exist, and `start_time` is `NOT NULL`.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration add_request_fields_to_appointments_table
```

```php
<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_request_fields_to_appointments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // A request has no real schedule yet — staff fill these in on confirm.
            $table->dateTime('start_time')->nullable()->change();
            $table->dateTime('end_time')->nullable()->change();
            $table->string('type')->nullable()->change();
            $table->foreignId('provider_id')->nullable()->change();

            $table->string('service_interest')->nullable();
            $table->string('dentist_preference')->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time_of_day')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'service_interest',
                'dentist_preference',
                'preferred_date',
                'preferred_time_of_day',
                'notes',
            ]);

            $table->dateTime('start_time')->nullable(false)->change();
            $table->dateTime('end_time')->nullable(false)->change();
            $table->string('type')->nullable(false)->change();
            $table->foreignId('provider_id')->nullable(false)->change();
        });
    }
};
```

Note: `foreignId('provider_id')->nullable()->change()` alters only the column definition (`MODIFY provider_id BIGINT UNSIGNED NULL`) — it does not touch the existing foreign-key constraint, so no constraint drop/re-add is needed.

- [ ] **Step 4: Create the clinic config**

```php
<?php
// config/clinic.php

use Carbon\Carbon;

return [

    /*
     * Weekdays the clinic is closed, as Carbon day-of-week integers
     * (Carbon::SUNDAY === 0). This is the authority for booking-date
     * validation on both the server and the booking form — the CLINIC.hours
     * constant in PublicLayout.jsx is display copy only.
     */
    'closed_days' => [Carbon::SUNDAY],

];
```

- [ ] **Step 5: Update the model**

Replace the constants and `$fillable`/`$casts` in `app/Models/Appointment.php`, and add the conflict helper. The full file afterwards:

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    public const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];

    public const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'no_show', 'declined'];

    public const TIMES_OF_DAY = ['morning', 'afternoon'];

    /**
     * Statuses whose appointment no longer occupies its slot, so it should
     * not block another booking at the same time.
     */
    public const SLOT_FREEING_STATUSES = ['cancelled', 'declined', 'no_show'];

    protected $fillable = [
        'patient_id',
        'provider_id',
        'start_time',
        'end_time',
        'type',
        'status',
        'service_interest',
        'dentist_preference',
        'preferred_date',
        'preferred_time_of_day',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'preferred_date' => 'date:Y-m-d',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Does another appointment for this provider overlap [$start, $end)?
     *
     * Half-open on purpose: one appointment ending at 09:30 and the next
     * starting at 09:30 do not conflict. Pending requests (no start_time)
     * hold no slot, and cancelled/declined/no-show appointments release
     * theirs.
     */
    public static function hasConflict(int $providerId, Carbon $start, Carbon $end, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('provider_id', $providerId)
            ->whereNotNull('start_time')
            ->whereNotIn('status', self::SLOT_FREEING_STATUSES)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }
}
```

- [ ] **Step 6: Add the factory state**

Add to `database/factories/AppointmentFactory.php` (after `definition()`):

```php
    /**
     * A pending guest request: no real schedule, no provider yet.
     */
    public function requested(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => now()->addWeek()->toDateString(),
            'preferred_time_of_day' => 'morning',
            'notes' => null,
        ]);
    }
```

- [ ] **Step 7: Migrate and run the test — confirm PASS**

```bash
php artisan migrate
php artisan test tests/Feature/AppointmentTest.php
```

Expected: the new test passes and all 9 pre-existing appointment tests still pass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add request fields and statuses to Appointment

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Scheduling integrity guards

**Files:**
- Modify: `app/Http/Controllers/Admin/AppointmentController.php`
- Test: `tests/Feature/AppointmentTest.php` (add tests)

**Interfaces:**
- Consumes: `Appointment::hasConflict()`, `Appointment::SLOT_FREEING_STATUSES`, `Appointment::STATUSES` (Task 1).
- Produces: `store` and `update` now reject overlapping appointments with a validation error on `start_time`; `update` rejects a transition to `scheduled` unless `start_time`, `end_time`, `provider_id`, and `type` are all present (on the record or in the request). Task 6's Confirm action depends on this behavior.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AppointmentTest.php`:

```php
    public function test_overlapping_appointment_for_the_same_provider_is_rejected(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Appointment::count());
    }

    public function test_back_to_back_appointments_for_the_same_provider_are_allowed(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 09:30:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_same_time_for_a_different_provider_is_allowed(): void
    {
        $this->actingUser();
        Appointment::factory()->create([
            'provider_id' => Provider::factory()->create()->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => Provider::factory()->create()->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_cancelled_appointment_does_not_block_its_old_slot(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'status' => 'cancelled',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_pending_request_does_not_block_a_slot(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->requested()->create();

        $response = $this->post(route('appointments.store'), [
            'patient_id' => Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_saving_an_appointment_over_itself_is_not_a_conflict(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'type' => 'cleaning',
        ]);

        $response = $this->patch(route('appointments.update', $appointment), [
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_rescheduling_onto_another_appointment_is_rejected(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
        ]);
        $moving = Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-02 09:00:00',
            'end_time' => '2026-09-02 10:00:00',
        ]);

        $response = $this->patch(route('appointments.update', $moving), [
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame('2026-09-02 09:00:00', $moving->fresh()->start_time->toDateTimeString());
    }

    public function test_a_request_cannot_be_scheduled_without_a_time(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'scheduled',
        ]);

        $response->assertSessionHasErrors(['start_time', 'end_time', 'provider_id', 'type']);
        $this->assertSame('requested', $request->fresh()->status);
    }

    public function test_a_request_can_be_confirmed_with_a_full_schedule(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();
        $provider = Provider::factory()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'scheduled',
            'provider_id' => $provider->id,
            'start_time' => '2026-09-02 09:00:00',
            'end_time' => '2026-09-02 09:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertRedirect();
        $confirmed = $request->fresh();
        $this->assertSame('scheduled', $confirmed->status);
        $this->assertSame($provider->id, $confirmed->provider_id);
        $this->assertSame('2026-09-02 09:00:00', $confirmed->start_time->toDateTimeString());
        $this->assertSame('2026-09-02 09:30:00', $confirmed->end_time->toDateTimeString());
        $this->assertSame('cleaning', $confirmed->type);
    }

    public function test_a_request_can_be_declined_without_a_time(): void
    {
        $this->actingUser();
        $request = Appointment::factory()->requested()->create();

        $response = $this->patch(route('appointments.update', $request), [
            'status' => 'declined',
        ]);

        $response->assertRedirect();
        $this->assertSame('declined', $request->fresh()->status);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

```bash
php artisan test tests/Feature/AppointmentTest.php
```

Expected: the overlap and confirm-guard tests FAIL (no guards exist yet — overlapping appointments are currently created happily, and a request flips to `scheduled` with null times). The back-to-back / different-provider / cancelled / self-save / decline tests may already pass; that's fine and expected.

- [ ] **Step 3: Add the guards to the controller**

Replace `store` and `update` in `app/Http/Controllers/Admin/AppointmentController.php`, and add the two imports:

```php
use Illuminate\Validation\ValidationException;
```

```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'type' => ['required', Rule::in(Appointment::TYPES)],
        ]);

        $this->assertNoConflict(
            (int) $validated['provider_id'],
            Carbon::parse($validated['start_time']),
            Carbon::parse($validated['end_time']),
        );

        $validated['status'] = 'scheduled';

        Appointment::create($validated);

        return back();
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $validated = $request->validate([
            'provider_id' => ['sometimes', 'required', 'exists:providers,id'],
            'start_time' => ['sometimes', 'required', 'date'],
            'end_time' => ['sometimes', 'required', 'date', 'after:start_time'],
            'type' => ['sometimes', 'required', Rule::in(Appointment::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(Appointment::STATUSES)],
        ]);

        // The values this appointment will actually hold once saved, whether
        // they arrived in this request or were already on the record.
        $status = $validated['status'] ?? $appointment->status;
        $providerId = isset($validated['provider_id'])
            ? (int) $validated['provider_id']
            : $appointment->provider_id;
        $startTime = isset($validated['start_time'])
            ? Carbon::parse($validated['start_time'])
            : $appointment->start_time;
        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $appointment->end_time;
        $type = $validated['type'] ?? $appointment->type;

        if ($status === 'scheduled') {
            $this->assertSchedulable($startTime, $endTime, $providerId, $type);
        }

        if ($providerId && $startTime && $endTime && ! in_array($status, Appointment::SLOT_FREEING_STATUSES, true)) {
            $this->assertNoConflict($providerId, $startTime, $endTime, $appointment->id);
        }

        $appointment->update($validated);

        return back();
    }

    /**
     * A scheduled appointment must be a complete one. Without this, a request
     * could be marked scheduled with no start_time — and the FullCalendar feed
     * filters on start_time, so it would look confirmed but never appear.
     */
    private function assertSchedulable(?Carbon $startTime, ?Carbon $endTime, ?int $providerId, ?string $type): void
    {
        $missing = [];

        if (! $startTime) {
            $missing['start_time'] = 'A start time is required to schedule an appointment.';
        }

        if (! $endTime) {
            $missing['end_time'] = 'An end time is required to schedule an appointment.';
        }

        if (! $providerId) {
            $missing['provider_id'] = 'A provider is required to schedule an appointment.';
        }

        if (! $type) {
            $missing['type'] = 'A type is required to schedule an appointment.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function assertNoConflict(int $providerId, Carbon $startTime, Carbon $endTime, ?int $ignoreId = null): void
    {
        if (Appointment::hasConflict($providerId, $startTime, $endTime, $ignoreId)) {
            throw ValidationException::withMessages([
                'start_time' => 'This provider already has an appointment overlapping that time.',
            ]);
        }
    }
```

- [ ] **Step 4: Run the tests — confirm PASS**

```bash
php artisan test tests/Feature/AppointmentTest.php
```

Expected: all tests pass, including the 9 pre-existing ones.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Guard against double-booking and incomplete scheduling

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: BookingController — public request submission

**Files:**
- Create: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BookingTest.php`

**Interfaces:**
- Consumes: `Appointment::TIMES_OF_DAY`, the new appointment columns, `config('clinic.closed_days')` (Task 1).
- Produces: route `bookings.store` (public `POST /book`). Task 4's `Book.jsx` posts to it.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/BookingTest.php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Monday, comfortably in the future — the clinic is open, and it passes
     * the after_or_equal:today rule regardless of when the suite runs.
     */
    private function openDate(): string
    {
        return now()->addWeek()->next(\Carbon\Carbon::MONDAY)->toDateString();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => $this->openDate(),
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
        ], $overrides);
    }

    public function test_guest_can_submit_an_appointment_request(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('appointments', [
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
            'start_time' => null,
            'end_time' => null,
            'provider_id' => null,
            'type' => null,
        ]);
        $this->assertSame($this->openDate(), Appointment::first()->preferred_date->toDateString());
    }

    public function test_dentist_preference_and_notes_are_optional(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'dentist_preference' => null,
            'notes' => null,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', [
            'status' => 'requested',
            'dentist_preference' => null,
            'notes' => null,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $response = $this->post(route('bookings.store'), []);

        $response->assertSessionHasErrors([
            'name',
            'email',
            'phone',
            'service_interest',
            'preferred_date',
            'preferred_time_of_day',
        ]);
        $this->assertSame(0, Appointment::count());
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertSessionHasErrors('email');
    }

    public function test_preferred_date_must_be_a_real_date(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => 'next tuesday-ish',
        ]));

        $response->assertSessionHasErrors('preferred_date');
    }

    public function test_preferred_date_cannot_be_in_the_past(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => now()->subDay()->toDateString(),
        ]));

        $response->assertSessionHasErrors('preferred_date');
    }

    public function test_preferred_date_cannot_be_a_day_the_clinic_is_closed(): void
    {
        $sunday = now()->addWeek()->next(\Carbon\Carbon::SUNDAY)->toDateString();

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $sunday,
        ]));

        $response->assertSessionHasErrors('preferred_date');
        $this->assertSame(0, Appointment::count());
    }

    public function test_preferred_time_of_day_must_be_morning_or_afternoon(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_time_of_day' => 'midnight',
        ]));

        $response->assertSessionHasErrors('preferred_time_of_day');
    }

    public function test_an_existing_patient_with_the_same_email_is_reused(): void
    {
        $existing = Patient::factory()->create(['email' => 'angela@example.com']);

        $this->post(route('bookings.store'), $this->validPayload());

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Appointment::first()->patient_id);
    }

    public function test_patient_email_matching_is_case_insensitive(): void
    {
        $existing = Patient::factory()->create(['email' => 'angela@example.com']);

        $this->post(route('bookings.store'), $this->validPayload([
            'email' => 'ANGELA@Example.COM',
        ]));

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Appointment::first()->patient_id);
    }

    public function test_a_new_patient_is_created_when_no_email_matches(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'name' => 'Rico Dela Cruz',
            'email' => 'rico@example.com',
        ]));

        $this->assertSame(1, Patient::count());
        $this->assertDatabaseHas('patients', [
            'first_name' => 'Rico',
            'last_name' => 'Dela Cruz',
            'email' => 'rico@example.com',
            'phone' => '09171234567',
        ]);
    }

    public function test_a_single_word_name_becomes_the_first_name(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'name' => 'Madonna',
            'email' => 'madonna@example.com',
        ]));

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Madonna',
            'last_name' => '',
        ]);
    }

    public function test_a_request_does_not_appear_on_the_staff_calendar_feed(): void
    {
        $this->post(route('bookings.store'), $this->validPayload());

        $this->actingAs(\App\Models\User::factory()->create());

        $response = $this->getJson(route('appointments.events', [
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->addMonths(3)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonCount(0);
    }
}
```

- [ ] **Step 2: Run them to confirm they fail**

```bash
php artisan test tests/Feature/BookingTest.php
```

Expected: FAIL — `route('bookings.store')` is undefined.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/BookingController.php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'service_interest' => ['required', 'string', 'max:255'],
            'dentist_preference' => ['nullable', 'string', 'max:255'],
            'preferred_date' => ['required', 'date', 'after_or_equal:today', $this->clinicIsOpen()],
            'preferred_time_of_day' => ['required', Rule::in(Appointment::TIMES_OF_DAY)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $patient = $this->findOrCreatePatient(
            $validated['name'],
            $validated['email'],
            $validated['phone'],
        );

        // A request carries the guest's preference only. The real schedule —
        // start/end time, provider, type — is set by staff on confirmation.
        Appointment::create([
            'patient_id' => $patient->id,
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => $validated['service_interest'],
            'dentist_preference' => $validated['dentist_preference'] ?? null,
            'preferred_date' => $validated['preferred_date'],
            'preferred_time_of_day' => $validated['preferred_time_of_day'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back();
    }

    /**
     * Rejects dates falling on a weekday the clinic is closed.
     */
    private function clinicIsOpen(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (in_array(Carbon::parse($value)->dayOfWeek, config('clinic.closed_days'), true)) {
                $fail('The clinic is closed on that day. Please choose another date.');
            }
        };
    }

    private function findOrCreatePatient(string $name, string $email, string $phone): Patient
    {
        $email = Str::lower(trim($email));

        // Compared with an explicit LOWER() rather than relying on the column's
        // collation happening to be case-insensitive.
        $patient = Patient::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($patient) {
            return $patient;
        }

        [$firstName, $lastName] = $this->splitName($name);

        return Patient::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    /**
     * The form takes one "Name" field but Patient stores first and last
     * separately. Split on the first space; a single-word name becomes the
     * first name with an empty last name.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $position = strpos($name, ' ');

        if ($position === false) {
            return [$name, ''];
        }

        return [substr($name, 0, $position), substr($name, $position + 1)];
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import alongside the existing controller imports:

```php
use App\Http\Controllers\BookingController;
```

and the route immediately after the existing public `/contact` POST:

```php
Route::post('/book', [BookingController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('bookings.store');
```

- [ ] **Step 5: Run the tests — confirm PASS**

```bash
php artisan test tests/Feature/BookingTest.php
```

Expected: all 13 pass.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add public appointment request submission

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: The `/book` page

**Files:**
- Create: `resources/js/Pages/Public/Book.jsx`
- Modify: `app/Http/Controllers/PublicSiteController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PublicPagesTest.php` (add one test)

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `SectionHeading` (Phase 2); `services` and `dentists` data (Phase 2); `bookings.store` (Task 3); `config('clinic.closed_days')` (Task 1).
- Produces: route `book` (GET `/book`), accepting an optional `?service=` query param. Task 5's CTAs link to it.

- [ ] **Step 1: Add the failing test**

Add to `tests/Feature/PublicPagesTest.php`:

```php
    public function test_book_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('book'));

        $response->assertOk();
    }

    public function test_book_page_receives_a_prefilled_service_from_the_query_string(): void
    {
        $response = $this->get(route('book', ['service' => 'Teeth Whitening']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('initialService', 'Teeth Whitening')
        );
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: FAIL — `route('book')` is undefined.

- [ ] **Step 3: Add the controller method**

Add to `app/Http/Controllers/PublicSiteController.php`:

```php
    public function book(Request $request): Response
    {
        return Inertia::render('Public/Book', [
            'initialService' => $request->query('service'),
            'closedDays' => array_values(config('clinic.closed_days')),
        ]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, alongside the other public GET routes:

```php
Route::get('/book', [PublicSiteController::class, 'book'])->name('book');
```

- [ ] **Step 5: Build the booking page**

```jsx
// resources/js/Pages/Public/Book.jsx

import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ContactInfo from '@/Components/Public/ContactInfo';
import { services } from '@/Data/services';
import { dentists } from '@/Data/dentists';
import { CheckCircle2 } from 'lucide-react';

const inputClass =
    'mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-teal-700 focus:outline-none focus:ring-1 focus:ring-teal-700';

const TIMES_OF_DAY = [
    { value: 'morning', label: 'Morning' },
    { value: 'afternoon', label: 'Afternoon' },
];

function todayIsoDate() {
    const now = new Date();
    const offsetMinutes = now.getTimezoneOffset();

    return new Date(now.getTime() - offsetMinutes * 60_000).toISOString().slice(0, 10);
}

export default function Book({ initialService, closedDays }) {
    const [submitted, setSubmitted] = useState(false);
    const [dateWarning, setDateWarning] = useState(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        service_interest: initialService ?? '',
        dentist_preference: '',
        preferred_date: '',
        preferred_time_of_day: 'morning',
        name: '',
        email: '',
        phone: '',
        notes: '',
    });

    function onDateChange(value) {
        setData('preferred_date', value);

        if (value && closedDays.includes(new Date(`${value}T00:00:00`).getDay())) {
            setDateWarning('The clinic is closed on that day. Please choose another date.');
        } else {
            setDateWarning(null);
        }
    }

    function submit(e) {
        e.preventDefault();
        post(route('bookings.store'), {
            onSuccess: () => {
                reset();
                setDateWarning(null);
                setSubmitted(true);
            },
        });
    }

    return (
        <PublicLayout>
            <Head title="Book an Appointment" />

            <section className="py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-8">
                        <SectionHeading
                            align="left"
                            eyebrow="Book"
                            title="Request an appointment"
                            subtitle="Tell us what you need and when suits you. Our clinic team will review your request and confirm a time with you."
                        />

                        <ContactInfo />
                    </div>

                    <div className="rounded-lg border border-stone-200 bg-white p-8">
                        {submitted ? (
                            <div className="flex flex-col items-center gap-3 py-8 text-center">
                                <CheckCircle2 className="h-10 w-10 text-teal-700" aria-hidden="true" />
                                <h3 className="text-lg font-semibold text-stone-900">
                                    Appointment request submitted
                                </h3>
                                <p className="text-sm leading-relaxed text-stone-600">
                                    Thanks — our clinic team will review your request and get in touch to
                                    confirm a time. This is not a confirmed appointment yet.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
                                <div>
                                    <label htmlFor="service_interest" className="block text-sm font-medium text-stone-700">
                                        Service
                                    </label>
                                    <select
                                        id="service_interest"
                                        value={data.service_interest}
                                        onChange={(e) => setData('service_interest', e.target.value)}
                                        aria-describedby={errors.service_interest ? 'service-error' : undefined}
                                        className={inputClass}
                                    >
                                        <option value="">Select a service</option>
                                        {services.map((service) => (
                                            <option key={service.slug} value={service.name}>
                                                {service.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.service_interest && (
                                        <p id="service-error" className="mt-1 text-sm text-red-600">
                                            {errors.service_interest}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="dentist_preference" className="block text-sm font-medium text-stone-700">
                                        Dentist <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <select
                                        id="dentist_preference"
                                        value={data.dentist_preference}
                                        onChange={(e) => setData('dentist_preference', e.target.value)}
                                        className={inputClass}
                                    >
                                        <option value="">No preference</option>
                                        {dentists.map((dentist) => (
                                            <option key={dentist.slug} value={dentist.name}>
                                                {dentist.name} — {dentist.specialty}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="preferred_date" className="block text-sm font-medium text-stone-700">
                                        Preferred date
                                    </label>
                                    <input
                                        id="preferred_date"
                                        type="date"
                                        min={todayIsoDate()}
                                        value={data.preferred_date}
                                        onChange={(e) => onDateChange(e.target.value)}
                                        aria-describedby={
                                            errors.preferred_date || dateWarning ? 'date-error' : undefined
                                        }
                                        className={inputClass}
                                    />
                                    {(dateWarning || errors.preferred_date) && (
                                        <p id="date-error" className="mt-1 text-sm text-red-600">
                                            {dateWarning ?? errors.preferred_date}
                                        </p>
                                    )}
                                </div>

                                <fieldset>
                                    <legend className="block text-sm font-medium text-stone-700">
                                        Preferred time of day
                                    </legend>
                                    <div className="mt-2 flex gap-4">
                                        {TIMES_OF_DAY.map((option) => (
                                            <label key={option.value} className="flex items-center gap-2 text-sm text-stone-700">
                                                <input
                                                    type="radio"
                                                    name="preferred_time_of_day"
                                                    value={option.value}
                                                    checked={data.preferred_time_of_day === option.value}
                                                    onChange={(e) => setData('preferred_time_of_day', e.target.value)}
                                                    className="text-teal-700 focus:ring-teal-700"
                                                />
                                                {option.label}
                                            </label>
                                        ))}
                                    </div>
                                    {errors.preferred_time_of_day && (
                                        <p className="mt-1 text-sm text-red-600">{errors.preferred_time_of_day}</p>
                                    )}
                                </fieldset>

                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium text-stone-700">
                                        Name
                                    </label>
                                    <input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        aria-describedby={errors.name ? 'name-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.name && (
                                        <p id="name-error" className="mt-1 text-sm text-red-600">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="email" className="block text-sm font-medium text-stone-700">
                                        Email
                                    </label>
                                    <input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        aria-describedby={errors.email ? 'email-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.email && (
                                        <p id="email-error" className="mt-1 text-sm text-red-600">
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="phone" className="block text-sm font-medium text-stone-700">
                                        Phone
                                    </label>
                                    <input
                                        id="phone"
                                        type="tel"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        aria-describedby={errors.phone ? 'phone-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.phone && (
                                        <p id="phone-error" className="mt-1 text-sm text-red-600">
                                            {errors.phone}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="notes" className="block text-sm font-medium text-stone-700">
                                        Anything else we should know? <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="notes"
                                        rows={3}
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        className={inputClass}
                                    />
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-md bg-teal-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Sending…' : 'Request Appointment'}
                                </button>

                                <p className="text-xs leading-relaxed text-stone-500">
                                    Submitting a request doesn&rsquo;t book the appointment — our team will
                                    confirm a time with you first.
                                </p>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 6: Run the tests — confirm PASS**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: all pass.

- [ ] **Step 7: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/book`. Confirm: all 12 services appear in the Service dropdown; the 3 dentists appear with "No preference" first; the date input won't accept a past date; picking a Sunday shows the closed-day message inline; submitting empty shows errors under Service, Preferred date, Name, Email, Phone; a valid submit shows the "Appointment request submitted" panel (and never the word "confirmed"). Visit `/book?service=Braces` and confirm the Service dropdown is pre-selected to Braces. Stop the server.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add public appointment request page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Public-site CTAs pointing at `/book`

**Files:**
- Modify: `resources/js/Components/Public/ServiceCard.jsx`
- Modify: `resources/js/Layouts/PublicLayout.jsx`
- Modify: `resources/js/Pages/Public/Home.jsx`

**Interfaces:**
- Consumes: route `book` (Task 4).
- Produces: no new interfaces — wiring only.

- [ ] **Step 1: Point `ServiceCard` at the booking page**

In `resources/js/Components/Public/ServiceCard.jsx`, change the link target and label:

```jsx
            <Link
                href={route('book', { service: service.name })}
                className="inline-flex items-center gap-1 text-sm font-medium text-teal-700 hover:text-teal-800"
            >
                Book this service
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
```

- [ ] **Step 2: Add the nav entry**

In `resources/js/Layouts/PublicLayout.jsx`, add `book` to `NAV_LINKS` (before `contact`, so the footer lists it in a sensible order):

```jsx
const NAV_LINKS = [
    { name: 'home', label: 'Home' },
    { name: 'services', label: 'Services' },
    { name: 'dentists', label: 'Dentists' },
    { name: 'about', label: 'About' },
    { name: 'book', label: 'Book' },
    { name: 'contact', label: 'Contact' },
];
```

The desktop nav renders every link except `contact` as a plain text link and `contact` as the filled button. Change that so booking is the prominent action and Contact returns to a text link — replace the desktop `<nav>` block's contents:

```jsx
                    <nav className="hidden items-center gap-8 md:flex">
                        {NAV_LINKS.filter((l) => l.name !== 'book').map((link) => (
                            <Link
                                key={link.name}
                                href={route(link.name)}
                                className={`text-sm font-medium transition-colors ${
                                    route().current(link.name) ? 'text-teal-700' : 'text-stone-600 hover:text-teal-700'
                                }`}
                            >
                                {link.label}
                            </Link>
                        ))}
                        <Link
                            href={route('book')}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-teal-800"
                        >
                            Book an Appointment
                        </Link>
                    </nav>
```

The mobile nav already maps over all of `NAV_LINKS`, so it picks up the new entry with no further change.

- [ ] **Step 2b: Give the footer's Book link a clearer label**

The footer also maps over `NAV_LINKS`, which would render the new entry as just "Book". That's fine in a compact footer column, so leave the footer markup as-is — no change needed here. (This step exists only to record the decision, so a reviewer doesn't read the short label as an oversight.)

- [ ] **Step 3: Add the hero CTA**

In `resources/js/Pages/Public/Home.jsx`, replace the single hero button with a pair — booking primary, contact secondary:

```jsx
                        <div className="flex flex-wrap items-center gap-3">
                            <Button href={route('book')}>Book an Appointment</Button>
                            <Button href={route('contact')} variant="outline">
                                Contact Us
                            </Button>
                        </div>
```

And in the closing "Have a question?" panel near the bottom of the same file, leave the existing `Contact Us` button as-is — that section is explicitly about questions, not booking.

- [ ] **Step 4: Verify the suite still passes**

```bash
php artisan test
```

Expected: everything passes. (No backend changed here, but `PublicPagesTest` renders these components server-side via Inertia's SSR-less smoke check, so a broken `route()` call would surface.)

- [ ] **Step 5: Manual check**

```bash
npm run build
php artisan serve
```

Confirm: the header shows "Book an Appointment" as the filled button on every public page, with Contact as a plain link; the mobile menu lists Book; the Home hero shows both CTAs; a service card's "Book this service" lands on `/book` with that service pre-selected. Stop the server.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Point public CTAs at the booking page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Staff Requests panel

**Files:**
- Modify: `app/Http/Controllers/Admin/AppointmentController.php`
- Modify: `resources/js/Pages/Appointments/Index.jsx`
- Test: `tests/Feature/AppointmentTest.php` (add tests)

**Interfaces:**
- Consumes: `requested` status and the request columns (Task 1); the guards on `update` (Task 2).
- Produces: an `requests` prop on the `Appointments/Index` page — an array of `{id, patient_name, patient_email, patient_phone, service_interest, dentist_preference, preferred_date, preferred_time_of_day, notes}`, ordered by `preferred_date` ascending.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AppointmentTest.php`:

```php
    public function test_index_returns_pending_requests_oldest_preferred_date_first(): void
    {
        $this->actingUser();
        $later = Appointment::factory()->requested()->create([
            'preferred_date' => '2026-09-10',
        ]);
        $sooner = Appointment::factory()->requested()->create([
            'preferred_date' => '2026-09-02',
        ]);

        $response = $this->get(route('appointments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('requests.0.id', $sooner->id)
            ->where('requests.1.id', $later->id)
        );
    }

    public function test_index_excludes_non_requested_appointments_from_requests(): void
    {
        $this->actingUser();
        Appointment::factory()->create(['status' => 'scheduled']);
        Appointment::factory()->requested()->create();
        Appointment::factory()->requested()->create(['status' => 'declined']);

        $response = $this->get(route('appointments.index'));

        $response->assertInertia(fn ($page) => $page->has('requests', 1));
    }

    public function test_request_payload_includes_the_details_staff_need(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create([
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
        ]);
        Appointment::factory()->requested()->create([
            'patient_id' => $patient->id,
            'service_interest' => 'Root Canal Treatment',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => '2026-09-02',
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
        ]);

        $response = $this->get(route('appointments.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('requests.0.patient_name', 'Angela Reyes')
            ->where('requests.0.patient_email', 'angela@example.com')
            ->where('requests.0.patient_phone', '09171234567')
            ->where('requests.0.service_interest', 'Root Canal Treatment')
            ->where('requests.0.dentist_preference', 'Dr. Elena Santos')
            ->where('requests.0.preferred_date', '2026-09-02')
            ->where('requests.0.preferred_time_of_day', 'morning')
            ->where('requests.0.notes', 'Upper-right tooth pain.')
        );
    }
```

- [ ] **Step 2: Run them to confirm they fail**

```bash
php artisan test tests/Feature/AppointmentTest.php
```

Expected: FAIL — the page has no `requests` prop.

- [ ] **Step 3: Return requests from `index`**

Replace `index` in `app/Http/Controllers/Admin/AppointmentController.php`:

```php
    public function index(): Response
    {
        return Inertia::render('Appointments/Index', [
            'patients' => Patient::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'requests' => Appointment::with('patient')
                ->where('status', 'requested')
                ->orderBy('preferred_date')
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'patient_name' => $appointment->patient->full_name,
                    'patient_email' => $appointment->patient->email,
                    'patient_phone' => $appointment->patient->phone,
                    'service_interest' => $appointment->service_interest,
                    'dentist_preference' => $appointment->dentist_preference,
                    'preferred_date' => $appointment->preferred_date?->toDateString(),
                    'preferred_time_of_day' => $appointment->preferred_time_of_day,
                    'notes' => $appointment->notes,
                ]),
        ]);
    }
```

- [ ] **Step 4: Run the tests — confirm PASS**

```bash
php artisan test tests/Feature/AppointmentTest.php
```

Expected: all pass.

- [ ] **Step 5: Add the Requests panel to the page**

In `resources/js/Pages/Appointments/Index.jsx`:

Update the status list and the component signature:

```jsx
const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'no_show', 'declined'];

export default function Index({ patients, providers, requests }) {
```

Add a confirm-modal opener and a decline handler alongside the existing handlers (after `onEventDrop`):

```jsx
    function onConfirmRequest(request) {
        setData({
            patient_id: '',
            provider_id: '',
            start_time: '',
            end_time: '',
            type: 'checkup',
            status: 'scheduled',
        });
        setModal({ mode: 'confirm', id: request.id, request });
    }

    function onDeclineRequest(request) {
        router.patch(
            route('appointments.update', request.id),
            { status: 'declined' },
            { preserveScroll: true }
        );
    }
```

Extend `submit` to handle the new mode — `confirm` sends the full schedule plus the status, which is exactly what the confirm-transition guard requires:

```jsx
    function submit(e) {
        e.preventDefault();
        if (modal.mode === 'create') {
            post(route('appointments.store'), {
                onSuccess: () => {
                    setModal(null);
                    refetch();
                },
            });
        } else {
            patch(route('appointments.update', modal.id), {
                onSuccess: () => {
                    setModal(null);
                    refetch();
                },
            });
        }
    }
```

(No change needed — `confirm` and `edit` both take the `patch` branch. This step records that the existing branch already covers it.)

Render the panel above the calendar, inside the existing `<div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">` and before the calendar's wrapper div:

```jsx
                {requests.length > 0 && (
                    <div className="mb-6 bg-white shadow rounded">
                        <div className="border-b px-4 py-3">
                            <h3 className="font-semibold">
                                Appointment requests
                                <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                    {requests.length} pending
                                </span>
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Submitted from the public site. Confirming lets you set the real
                                appointment time — the date and time of day below are the patient&rsquo;s
                                preference.
                            </p>
                        </div>
                        <div className="divide-y">
                            {requests.map((request) => (
                                <div key={request.id} className="flex items-start justify-between gap-4 p-4">
                                    <div className="text-sm">
                                        <div className="font-medium">{request.patient_name}</div>
                                        <div className="text-gray-500">
                                            {request.patient_email}
                                            {request.patient_phone && ` · ${request.patient_phone}`}
                                        </div>
                                        <div className="mt-1">{request.service_interest}</div>
                                        <div className="text-gray-500">
                                            Prefers {request.preferred_date} ({request.preferred_time_of_day})
                                            {' · '}
                                            {request.dentist_preference ?? 'No dentist preference'}
                                        </div>
                                        {request.notes && (
                                            <p className="mt-1 text-gray-700">{request.notes}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => onConfirmRequest(request)}
                                            className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                                        >
                                            Confirm
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => onDeclineRequest(request)}
                                            className="rounded border px-3 py-1.5 text-sm text-gray-700"
                                        >
                                            Decline
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
```

Finally, teach the modal about the `confirm` mode. Change the modal heading:

```jsx
                        <h3 className="font-semibold">
                            {modal.mode === 'create' && 'New appointment'}
                            {modal.mode === 'edit' && 'Edit appointment'}
                            {modal.mode === 'confirm' && `Confirm request — ${modal.request.patient_name}`}
                        </h3>
```

Show the patient's preference as read-only context, and the provider picker, when confirming — insert directly after that heading:

```jsx
                        {modal.mode === 'confirm' && (
                            <>
                                <p className="rounded bg-gray-50 p-3 text-sm text-gray-600">
                                    Requested {modal.request.service_interest} · prefers{' '}
                                    {modal.request.preferred_date} ({modal.request.preferred_time_of_day})
                                    {modal.request.dentist_preference && ` · ${modal.request.dentist_preference}`}
                                </p>
                                <div>
                                    <label className="block text-sm mb-1">Provider</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={data.provider_id}
                                        onChange={(e) => setData('provider_id', e.target.value)}
                                    >
                                        <option value="">Select a provider</option>
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                    {errors.provider_id && <p className="text-sm text-red-600">{errors.provider_id}</p>}
                                </div>
                            </>
                        )}
```

Add the start/end time inputs, which `create` and `edit` currently get from the calendar selection but `confirm` has to supply by hand — insert after the Type select:

```jsx
                        {modal.mode === 'confirm' && (
                            <>
                                <div>
                                    <label className="block text-sm mb-1">Start</label>
                                    <input
                                        type="datetime-local"
                                        className="w-full border rounded px-3 py-2"
                                        value={data.start_time}
                                        onChange={(e) => setData('start_time', e.target.value)}
                                    />
                                    {errors.start_time && <p className="text-sm text-red-600">{errors.start_time}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm mb-1">End</label>
                                    <input
                                        type="datetime-local"
                                        className="w-full border rounded px-3 py-2"
                                        value={data.end_time}
                                        onChange={(e) => setData('end_time', e.target.value)}
                                    />
                                    {errors.end_time && <p className="text-sm text-red-600">{errors.end_time}</p>}
                                </div>
                            </>
                        )}
```

The existing `{modal.mode === 'edit' && ...}` status select stays as-is — a confirm always sends `status: 'scheduled'`, which is already in the form's initial data set by `onConfirmRequest`.

- [ ] **Step 6: Manual check**

```bash
npm run build
php artisan serve
```

In one browser, submit a request at `/book`. Log in in another (or log in after), visit `/appointments`, and confirm: the Requests panel appears above the calendar with the pending count, showing name, contact, service, preference, and notes. Click **Confirm** — set a provider, type, start and end, save; confirm the appointment now appears on the calendar and has left the Requests panel. Submit another request and click **Decline** — confirm it disappears from the panel and never reaches the calendar. Try confirming with the start time left blank and confirm an inline error appears rather than a silent success. Stop the server.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add staff requests panel with confirm and decline

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: Final QA and regression check

**Files:** none (verification only).

**Interfaces:** none — this task consumes everything built in Tasks 1–6 and confirms it together.

- [ ] **Step 1: Run the full automated test suite**

```bash
php artisan test
```

Expected: every test passes — v1's, Phase 2's, and this phase's.

- [ ] **Step 2: Production build**

```bash
npm run build
```

Expected: builds cleanly, no missing-module errors or warnings.

- [ ] **Step 3: Full manual walkthrough**

```bash
php artisan serve
```

As a guest:
- Visit `/`, `/services`, `/dentists`, `/about`, `/book`, `/contact` — each renders fully.
- Confirm the header's "Book an Appointment" button appears on every public page, and the mobile hamburger menu lists Book.
- Submit a booking request with fields missing — confirm inline errors. Pick a Sunday — confirm the closed-day message. Submit a valid one — confirm the panel reads "Appointment request submitted" and never implies confirmation.
- Tab through `/book` with the keyboard only — every field, the radio group, and the submit button reachable with a visible focus ring.
- Confirm `/contact` still works unchanged for general inquiries.

As a logged-in staff user:
- Log in, confirm you still land on `/dashboard`.
- Confirm `/patients`, `/providers`, `/inquiries` all behave exactly as before.
- On `/appointments`: the calendar still renders, dragging an event still reschedules it, and creating an appointment by selecting a range still works.
- Try creating an appointment that overlaps an existing one for the same provider — confirm it's rejected with an inline error, and that a back-to-back one is accepted.
- Confirm a request, then verify it appears on the calendar. Decline another, and verify it doesn't.

Stop the server once all of the above is confirmed.

- [ ] **Step 4: Commit**

If Step 3 surfaced no fixes, there's nothing to commit — it's a verification pass. If any fix was needed, commit it now:

```bash
git add -A
git commit -m "Fix issues found in Phase 3 final QA

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Plan self-review notes

- **Spec coverage:** Data model changes incl. the `dentist_preference`-not-`provider_id` decision and `config/clinic.php` (Task 1); double-booking and confirm-transition guards (Task 2); `BookingController` with case-insensitive patient matching, name splitting, and closed-day validation (Task 3); the `/book` page, success-state wording, and `?service=` prefill (Task 4); ServiceCard / nav / hero CTAs (Task 5); staff Requests panel with confirm-via-existing-PATCH and one-click decline (Task 6); regression and accessibility verification (Task 7). Every section of `docs/superpowers/specs/2026-08-25-appointment-booking-design.md` maps to a task.
- **Placeholder scan:** no TBD/TODO; every step carries complete, runnable code. Two steps (Task 5 Step 2b, Task 6 Step 5's `submit`) deliberately record a *no-change-needed* decision rather than an edit, so a reviewer doesn't read the absence as an oversight.
- **Type consistency:** `Appointment::TIMES_OF_DAY` (Task 1) is the single source for `morning`/`afternoon` and is referenced by name in Task 3's validation; the frontend's `TIMES_OF_DAY` in Task 4 is a separate label-mapping array, intentionally not the same object. `hasConflict(int, Carbon, Carbon, ?int)` (Task 1) is called with exactly that signature from both `assertNoConflict` call sites (Task 2). The `requests` payload keys defined in Task 6 Step 3 match one-for-one with the keys read in Task 6 Step 5's JSX and asserted in Task 6 Step 1's test. Column names `service_interest`, `dentist_preference`, `preferred_date`, `preferred_time_of_day`, `notes` are identical across the migration, `$fillable`, the factory state, `BookingController@store`, and the index payload.
- **Ordering rationale:** the guards (Task 2) land before the Requests panel (Task 6) because Confirm depends on the confirm-transition guard for its error behavior, and before `BookingController` only incidentally — Tasks 2 and 3 are independent and could swap. Task 1 must be first: every later task depends on the schema.
- **Known risk:** Task 1's `->change()` calls on `provider_id` alter a column carrying a foreign-key constraint. On MariaDB this is a plain `MODIFY` and leaves the constraint intact, but if the migration errors there, the fallback is to drop the FK, change the column, and re-add it in the same migration. Flagged so the implementer doesn't improvise.
- **Deviation from the spec:** none in substance. One addition the spec left implicit: `preferred_date` also gets an `after_or_equal:today` rule, since a request for a past date is never actionable.
