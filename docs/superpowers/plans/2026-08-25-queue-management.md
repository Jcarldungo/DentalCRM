# Front-Desk Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a front-desk queue page (`/queue`) that moves today's patients through `scheduled → checked_in → in_treatment → completed` (or `scheduled → no_show`), plus walk-in check-in, using the existing `Appointment` model as the only source of truth.

**Architecture:** Two new statuses on `Appointment::STATUSES`; a new `Admin\QueueController` with `index()` (reads today's appointments, grouped by status) and `storeWalkIn()` (creates a same-day appointment already `checked_in`); all status *transitions* reuse the existing `PATCH /appointments/{appointment}` endpoint — no parallel transition system. One new Inertia page, `Queue/Index.jsx`, polls itself every 15s via `router.reload()`.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit (via `php artisan test`), MariaDB (`dentalcrm_testing`).

**Spec:** [`docs/superpowers/specs/2026-08-25-queue-management-design.md`](../specs/2026-08-25-queue-management-design.md)

## Global Constraints

- No new database table and no migration — `status` is a plain string column already validated by `Rule::in(Appointment::STATUSES)`.
- `checked_in` and `in_treatment` must NOT be added to `Appointment::SLOT_FREEING_STATUSES`.
- No new status-transition endpoints — every queue action PATCHes `route('appointments.update', $id)`.
- No queue-number field. Ordering is `start_time` ascending everywhere.
- Walk-in duration is a fixed `now()` → `now()->addMinutes(30)` — no duration-by-type config.
- No WebSockets/broadcasting — polling only, via `router.reload({ only: [...] })` on a 15s `setInterval`, cleaned up on unmount.
- Run PHP commands with `"/c/Users/Jann Carl/.config/herd/bin/php.bat"` (verified path on this machine — the `JC` path in `CLAUDE.md` is stale) from the repo root, `C:\dev\dentalcrm`.

---

## Task 1: Add `checked_in`/`in_treatment` statuses and prove the existing update endpoint drives them

**Files:**
- Modify: `app/Models/Appointment.php` (the `STATUSES` constant)
- Test: `tests/Feature/QueueTest.php` (new file)

**Interfaces:**
- Consumes: `Appointment::STATUSES` (existing constant), `AppointmentController::update()` at `PATCH /appointments/{appointment}` (existing, unmodified), `Appointment::factory()` (existing, `App\Models\User`, `App\Models\Provider`, `App\Models\Patient` factories).
- Produces: `Appointment::STATUSES` now includes `'checked_in'` and `'in_treatment'`, valid values for `PATCH /appointments/{appointment}`'s `status` field for every later task.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/QueueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_a_scheduled_appointment_can_be_checked_in_via_the_existing_update_endpoint(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'checked_in',
        ]);

        $response->assertRedirect();
        $this->assertSame('checked_in', $appointment->fresh()->status);
    }

    public function test_a_checked_in_appointment_can_start_treatment(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'checked_in']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'in_treatment',
        ]);

        $response->assertRedirect();
        $this->assertSame('in_treatment', $appointment->fresh()->status);
    }

    public function test_an_in_treatment_appointment_can_be_completed(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'in_treatment']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_a_scheduled_appointment_can_be_marked_no_show(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'no_show',
        ]);

        $response->assertRedirect();
        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_checked_in_and_in_treatment_still_occupy_their_provider_slot(): void
    {
        $this->actingUser();
        $provider = \App\Models\Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'status' => 'checked_in',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => \App\Models\Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Appointment::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: FAIL — `status` fails `Rule::in(Appointment::STATUSES)` validation for `checked_in`/`in_treatment` (session errors on `status`, so no redirect / status not updated).

- [ ] **Step 3: Add the two statuses**

In `app/Models/Appointment.php`, change:

```php
    public const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'no_show', 'declined'];
```

to:

```php
    public const STATUSES = ['requested', 'scheduled', 'checked_in', 'in_treatment', 'completed', 'cancelled', 'no_show', 'declined'];
```

Do **not** add `'checked_in'` or `'in_treatment'` to `SLOT_FREEING_STATUSES` — leave that constant exactly as it is.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Appointment.php tests/Feature/QueueTest.php
git commit -m "Add checked_in and in_treatment appointment statuses

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `QueueController@index` — today's board, scoped and grouped

**Files:**
- Create: `app/Http/Controllers/Admin/QueueController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/QueueTest.php`

**Interfaces:**
- Consumes: `Appointment` model (with `patient`, `provider` relations), `Appointment::STATUSES` (Task 1). Route-naming/middleware convention from the existing `auth` group in `routes/web.php` (see `appointments.index`).
- Produces: `GET /queue` (name `queue.index`) → Inertia component `'Queue/Index'` with props `todaysSchedule`, `waiting`, `nowServing`, `completed` — each an array of `{ id, patient_name, provider_name, type, start_time, end_time }` (`start_time`/`end_time` as ISO-8601 strings), ordered by `start_time` ascending. Task 4 (frontend) consumes exactly these four prop names and this shape.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/QueueTest.php` (inside the `QueueTest` class):

```php
    public function test_guest_cannot_view_the_queue(): void
    {
        $response = $this->get(route('queue.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_index_scopes_each_column_to_todays_appointments_by_status(): void
    {
        $this->actingUser();
        $today = now()->setTime(9, 0);

        $scheduled = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $today->clone(), 'end_time' => $today->clone()->addMinutes(30)]);
        $waiting = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->addHour(), 'end_time' => $today->clone()->addHour()->addMinutes(30)]);
        $serving = Appointment::factory()->create(['status' => 'in_treatment', 'start_time' => $today->clone()->addHours(2), 'end_time' => $today->clone()->addHours(2)->addMinutes(30)]);
        $completed = Appointment::factory()->create(['status' => 'completed', 'start_time' => $today->clone()->addHours(3), 'end_time' => $today->clone()->addHours(3)->addMinutes(30)]);

        // Outside today — must not appear in any column.
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $today->clone()->addDay(), 'end_time' => $today->clone()->addDay()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->subDay(), 'end_time' => $today->clone()->subDay()->addMinutes(30)]);

        $response = $this->get(route('queue.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Queue/Index')
            ->has('todaysSchedule', 1)
            ->where('todaysSchedule.0.id', $scheduled->id)
            ->has('waiting', 1)
            ->where('waiting.0.id', $waiting->id)
            ->has('nowServing', 1)
            ->where('nowServing.0.id', $serving->id)
            ->has('completed', 1)
            ->where('completed.0.id', $completed->id)
        );
    }

    public function test_index_orders_each_column_by_start_time_ascending(): void
    {
        $this->actingUser();
        $today = now()->setTime(9, 0);

        $later = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->addHours(2), 'end_time' => $today->clone()->addHours(2)->addMinutes(30)]);
        $sooner = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone(), 'end_time' => $today->clone()->addMinutes(30)]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('waiting.0.id', $sooner->id)
            ->where('waiting.1.id', $later->id)
        );
    }

    public function test_index_card_includes_patient_provider_and_type(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Cruz']);
        $provider = \App\Models\Provider::factory()->create(['name' => 'Dr. Santos']);
        Appointment::factory()->create([
            'status' => 'scheduled',
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'cleaning',
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 30),
        ]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('todaysSchedule.0.patient_name', 'Maria Cruz')
            ->where('todaysSchedule.0.provider_name', 'Dr. Santos')
            ->where('todaysSchedule.0.type', 'cleaning')
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: FAIL — `queue.index` route doesn't exist (`RouteNotFoundException`).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Admin/QueueController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    /**
     * Today's front-desk board: today's appointments, grouped by where the
     * patient is in their visit. Read once, grouped in memory — the table
     * is small enough at this scale (see CLAUDE.md Known gaps) that a
     * single query beats four near-identical ones.
     */
    public function index(): Response
    {
        $appointments = Appointment::with(['patient', 'provider'])
            ->whereDate('start_time', now()->toDateString())
            ->whereIn('status', ['scheduled', 'checked_in', 'in_treatment', 'completed'])
            ->orderBy('start_time')
            ->get();

        $forBoard = fn (Appointment $appointment) => [
            'id' => $appointment->id,
            'patient_name' => $appointment->patient->full_name,
            'provider_name' => $appointment->provider->name,
            'type' => $appointment->type,
            'start_time' => $appointment->start_time->toIso8601String(),
            'end_time' => $appointment->end_time->toIso8601String(),
        ];

        return Inertia::render('Queue/Index', [
            'todaysSchedule' => $appointments->where('status', 'scheduled')->map($forBoard)->values(),
            'waiting' => $appointments->where('status', 'checked_in')->map($forBoard)->values(),
            'nowServing' => $appointments->where('status', 'in_treatment')->map($forBoard)->values(),
            'completed' => $appointments->where('status', 'completed')->map($forBoard)->values(),
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import alongside the other `Admin` controller imports:

```php
use App\Http\Controllers\Admin\QueueController;
```

Then inside the existing `Route::middleware('auth')->group(function () { ... })` block, immediately after the `appointments.*` routes:

```php
    Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: PASS (9 tests total: 5 from Task 1 + 4 new).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/QueueController.php routes/web.php tests/Feature/QueueTest.php
git commit -m "Add QueueController@index for today's front-desk board

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `QueueController::storeWalkIn` — add a walk-in straight into Waiting

**Files:**
- Modify: `app/Http/Controllers/Admin/QueueController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/QueueTest.php`

**Interfaces:**
- Consumes: `Appointment::hasConflict(int $providerId, Carbon $start, Carbon $end, ?int $ignoreId = null): bool` (existing, unmodified), `Appointment::TYPES` (existing).
- Produces: `POST /queue/walk-ins` (name `queue.walkins.store`) — on success, creates an `Appointment` with `status: 'checked_in'`, `start_time = now()`, `end_time = now()->addMinutes(30)`; on provider conflict, redirects back with a `provider_id` validation error and creates nothing. Task 4 (frontend) submits `patient_id`, `provider_id`, `type` to this route.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/QueueTest.php`:

```php
    public function test_guest_cannot_add_a_walk_in(): void
    {
        $patient = \App\Models\Patient::factory()->create();
        $provider = \App\Models\Provider::factory()->create();

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_walk_in_is_created_checked_in_with_a_thirty_minute_block(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create();
        $provider = \App\Models\Provider::factory()->create();

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Appointment::count());
        $appointment = Appointment::first();
        $this->assertSame('checked_in', $appointment->status);
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame($provider->id, $appointment->provider_id);
        $this->assertSame('checkup', $appointment->type);
        $this->assertEqualsWithDelta(now()->timestamp, $appointment->start_time->timestamp, 5);
        $this->assertSame(
            $appointment->start_time->clone()->addMinutes(30)->timestamp,
            $appointment->end_time->timestamp
        );
    }

    public function test_a_walk_in_appears_in_the_waiting_column(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $provider = \App\Models\Provider::factory()->create();

        $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('waiting', 1)
            ->where('waiting.0.patient_name', 'Juan Dela Cruz')
        );
    }

    public function test_a_walk_in_conflicting_with_the_providers_schedule_is_rejected(): void
    {
        $this->actingUser();
        $provider = \App\Models\Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'scheduled',
            'start_time' => now(),
            'end_time' => now()->addMinutes(30),
        ]);

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => \App\Models\Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(1, Appointment::count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: FAIL — `queue.walkins.store` route doesn't exist.

- [ ] **Step 3: Add `storeWalkIn` to the controller**

In `app/Http/Controllers/Admin/QueueController.php`, add these four new imports (alongside the `Controller`, `Appointment`, `Inertia`, `Response` imports already there from Task 2 — don't duplicate those):

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
```

and add the method (after `index()`):

```php
    /**
     * A walk-in has no pre-existing appointment, so it skips Today's
     * Schedule entirely and lands directly in Waiting. Fixed 30-minute
     * block: there's no duration-by-appointment-type concept in the
     * codebase yet, and this phase doesn't add one.
     */
    public function storeWalkIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'type' => ['required', Rule::in(Appointment::TYPES)],
        ]);

        $start = now();
        $end = $start->clone()->addMinutes(30);

        if (Appointment::hasConflict((int) $validated['provider_id'], $start, $end)) {
            throw ValidationException::withMessages([
                'provider_id' => 'This provider already has an appointment overlapping that time.',
            ]);
        }

        Appointment::create([
            'patient_id' => $validated['patient_id'],
            'provider_id' => $validated['provider_id'],
            'type' => $validated['type'],
            'status' => 'checked_in',
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return back();
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, immediately after the `queue.index` route added in Task 2:

```php
    Route::post('/queue/walk-ins', [QueueController::class, 'storeWalkIn'])->name('queue.walkins.store');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: PASS (13 tests total).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/QueueController.php routes/web.php tests/Feature/QueueTest.php
git commit -m "Add walk-in check-in to the queue

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: `Queue/Index.jsx` — the four-column board, actions, walk-in modal, polling, nav link

**Files:**
- Create: `resources/js/Pages/Queue/Index.jsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`

**Interfaces:**
- Consumes: Inertia props `todaysSchedule`, `waiting`, `nowServing`, `completed` (Task 2's exact shape: `{ id, patient_name, provider_name, type, start_time, end_time }`); `route('appointments.update', id)` (existing) for transitions; `route('queue.walkins.store')` (Task 3) for walk-ins; `route('patients.index')`'s underlying data isn't reused, but the page needs a `patients` and `providers` list for the walk-in modal exactly like `Appointments/Index.jsx`'s create modal — see Step 3.
- Produces: the `/queue` page, reachable from the nav as "Queue".

- [ ] **Step 1: Extend `QueueController@index` to also pass patients/providers for the walk-in modal**

Add to `app/Http/Controllers/Admin/QueueController.php`'s `index()` method — this mirrors exactly what `AppointmentController@index` already does for its own create modal (`app/Http/Controllers/Admin/AppointmentController.php:26-28`). Add the imports:

```php
use App\Models\Patient;
use App\Models\Provider;
```

and change the `return Inertia::render(...)` call to include two more props:

```php
        return Inertia::render('Queue/Index', [
            'patients' => Patient::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'todaysSchedule' => $appointments->where('status', 'scheduled')->map($forBoard)->values(),
            'waiting' => $appointments->where('status', 'checked_in')->map($forBoard)->values(),
            'nowServing' => $appointments->where('status', 'in_treatment')->map($forBoard)->values(),
            'completed' => $appointments->where('status', 'completed')->map($forBoard)->values(),
        ]);
```

No test change needed — the existing Task 2 tests only assert on the four board props, which are unaffected.

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test --filter=QueueTest`
Expected: PASS (still 13 tests — confirms this addition didn't break the existing props).

- [ ] **Step 2: Create the queue page**

Create `resources/js/Pages/Queue/Index.jsx`:

```jsx
import { useEffect, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const POLL_INTERVAL_MS = 15000;
const BOARD_PROPS = ['todaysSchedule', 'waiting', 'nowServing', 'completed'];

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function Card({ appointment, children }) {
    return (
        <div className="rounded border bg-white p-3 text-sm shadow-sm">
            <div className="font-medium">{appointment.patient_name}</div>
            <div className="text-gray-500">
                {formatTime(appointment.start_time)} · {appointment.type} · {appointment.provider_name}
            </div>
            {children && <div className="mt-2 flex gap-2">{children}</div>}
        </div>
    );
}

function ActionButton({ onClick, children, variant = 'primary' }) {
    const className =
        variant === 'primary'
            ? 'rounded bg-gray-900 px-2 py-1 text-xs text-white'
            : 'rounded border px-2 py-1 text-xs text-gray-700';
    return (
        <button type="button" onClick={onClick} className={className}>
            {children}
        </button>
    );
}

function Column({ title, count, children }) {
    return (
        <div className="flex-1 min-w-[16rem]">
            <h3 className="mb-2 flex items-center gap-2 font-semibold">
                {title}
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                    {count}
                </span>
            </h3>
            <div className="space-y-2">{children}</div>
        </div>
    );
}

export default function Index({ patients, providers, todaysSchedule, waiting, nowServing, completed }) {
    const [showWalkInModal, setShowWalkInModal] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        patient_id: '',
        provider_id: '',
        type: 'checkup',
    });

    const pollRef = useRef(null);
    useEffect(() => {
        pollRef.current = setInterval(() => {
            router.reload({ only: BOARD_PROPS });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(pollRef.current);
    }, []);

    function setStatus(id, status) {
        router.patch(route('appointments.update', id), { status }, { preserveScroll: true });
    }

    function submitWalkIn(e) {
        e.preventDefault();
        post(route('queue.walkins.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowWalkInModal(false);
            },
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Queue</h2>
                    <button
                        type="button"
                        onClick={() => setShowWalkInModal(true)}
                        className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                    >
                        Add Walk-in
                    </button>
                </div>
            }
        >
            <Head title="Queue" />

            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div className="flex flex-wrap gap-6">
                    <Column title="Today's Schedule" count={todaysSchedule.length}>
                        {todaysSchedule.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'checked_in')}>
                                    Check In
                                </ActionButton>
                                <ActionButton variant="secondary" onClick={() => setStatus(appointment.id, 'no_show')}>
                                    No-show
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Waiting" count={waiting.length}>
                        {waiting.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'in_treatment')}>
                                    Start Treatment
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Now Serving" count={nowServing.length}>
                        {nowServing.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'completed')}>
                                    Complete
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Completed" count={completed.length}>
                        {completed.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment} />
                        ))}
                    </Column>
                </div>
            </div>

            {showWalkInModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4">
                    <form onSubmit={submitWalkIn} className="bg-white rounded p-6 w-full max-w-sm space-y-4">
                        <h3 className="font-semibold">Add walk-in</h3>

                        <div>
                            <label className="block text-sm mb-1">Patient</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={data.patient_id}
                                onChange={(e) => setData('patient_id', e.target.value)}
                            >
                                <option value="">Select a patient</option>
                                {patients.map((p) => (
                                    <option key={p.id} value={p.id}>{p.first_name} {p.last_name}</option>
                                ))}
                            </select>
                            {errors.patient_id && <p className="text-sm text-red-600">{errors.patient_id}</p>}
                        </div>

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

                        <div>
                            <label className="block text-sm mb-1">Type</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                            >
                                {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                            </select>
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowWalkInModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Add to queue
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Add the nav link**

In `resources/js/Layouts/AuthenticatedLayout.jsx`, add a desktop `NavLink` immediately after the "Appointments" one (after the closing `</NavLink>` at line 50, before the "Inquiries" link):

```jsx
                                <NavLink
                                    href={route('queue.index')}
                                    active={route().current('queue.index')}
                                >
                                    Queue
                                </NavLink>
```

And the matching mobile `ResponsiveNavLink`, immediately after the "Appointments" one (after line 178, before "Inquiries"):

```jsx
                        <ResponsiveNavLink
                            href={route('queue.index')}
                            active={route().current('queue.index')}
                        >
                            Queue
                        </ResponsiveNavLink>
```

- [ ] **Step 4: Build the frontend to catch syntax/import errors**

Run: `npm run build`
Expected: build succeeds with no errors (this codebase has no JS test runner — a clean build is the existing verification step for frontend-only changes, consistent with how the rest of the app is checked).

- [ ] **Step 5: Run the full backend test suite**

Run: `"/c/Users/Jann Carl/.config/herd/bin/php.bat" artisan test`
Expected: PASS, all tests (the full existing suite plus all 13 `QueueTest` tests) — zero regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/QueueController.php resources/js/Pages/Queue/Index.jsx resources/js/Layouts/AuthenticatedLayout.jsx
git commit -m "Add the queue board UI, walk-in modal, and nav link

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Final verification

After Task 4, manually confirm (via `composer run dev` or `php artisan serve` + `npm run dev`) the flows the spec calls out:

1. An existing `scheduled` appointment for today → Check In → Start Treatment → Complete, watching it move columns.
2. A `scheduled` appointment → No-show.
3. Add Walk-in → appears directly in Waiting → Start Treatment → Complete.
4. Add Walk-in for a provider with a conflicting appointment → rejected, no appointment created.

All four are already covered by `QueueTest.php`; this step is a manual sanity check of the UI wiring, not a substitute for the automated tests.
