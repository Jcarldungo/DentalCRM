# Dentist Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/workspace` page — a read-only per-provider clinical-prep view showing one date's scheduled appointments for a chosen provider (or all), each row carrying clinical-readiness badges (open treatment-plan items, active prescriptions) and a link into the patient's detail page.

**Architecture:** One controller (`WorkspaceController::index`, read-only), one GET route, one Inertia page (`Workspace/Index.jsx`), two nav-link additions. No model, no migration, no factory. The controller is a projection over existing `Appointment` / `Provider` / `Patient` / `TreatmentPlanItem` / `Prescription` data.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, PHPUnit 11, MariaDB (`dentalcrm_testing`).

**Spec:** `docs/superpowers/specs/2026-08-29-dentist-workspace-design.md`

## Global Constraints

- PHP via Herd: `"$HOME/.config/herd/bin/php.bat" artisan ...` for artisan/tests. `npm` on PATH. Run from repo root.
- Tests against MariaDB `dentalcrm_testing` (exists), not SQLite. A fresh worktree needs `npm run build` (Vite manifest) before `artisan test` or ~32 page-render tests fail.
- No RBAC, no auth changes, no `Provider`↔`User` link. Every authenticated user can open `/workspace` and pick any provider.
- No new model, migration, or factory. Read-only — the workspace writes nothing (`index` only; no `store`/`update`/`destroy`).
- Staff controllers in `App\Http\Controllers\Admin\`. Route names unprefixed. Tests flat in `tests/Feature/`.
- Appointment status set shown = `['scheduled', 'checked_in', 'in_treatment', 'completed']` — identical to `QueueController::index()`. `requested` / `cancelled` / `declined` / `no_show` are excluded.
- "Open" treatment-plan statuses = `['planned', 'scheduled', 'in_progress']` (same as `ACTIVE_TREATMENT_STATUSES` in `Patients/Show.jsx`). "Active" prescription = `status === 'active'`.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump()`, no unused imports, no commented-out code.
- Commits carry NO `Co-Authored-By` trailer (matches repo history). Short imperative subjects.

---

## File Structure

**Create:**
- `app/Http/Controllers/Admin/WorkspaceController.php` — `index()` only; validates `provider_id`/`date`, projects the day's appointments + badge counts
- `resources/js/Pages/Workspace/Index.jsx` — the page: provider `<select>`, date input, prev/today/next, time-ordered rows with badges
- `tests/Feature/WorkspaceTest.php` — feature tests

**Modify:**
- `routes/web.php` — `use` import + `GET /workspace` route in the `auth` group
- `resources/js/Layouts/AuthenticatedLayout.jsx` — a `<NavLink>` (desktop nav) and a `<ResponsiveNavLink>` (mobile nav), both "Workspace", after the "Queue" links
- `CLAUDE.md` — "Phase 6, sub-project 5" bullet under "Shipped so far"

---

## Task 1: `WorkspaceController` + route + tests

**Files:**
- Create: `app/Http/Controllers/Admin/WorkspaceController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/WorkspaceTest.php`

**Interfaces:**
- Consumes: existing models `Appointment` (`STATUSES`, `patient`/`provider` relations, `start_time`/`end_time` datetime casts, `type`/`status`/`notes`), `Provider` (`active` bool cast, `name`), `Patient` (`full_name` accessor, `date_of_birth` date cast), `TreatmentPlanItem` (`patient_id`, `status`), `Prescription` (`patient_id`, `status`). Existing `Appointment` factory (`requested()` state) and `TreatmentPlanItem` / `Prescription` factories (`discontinued()` state on the latter).
- Produces:
  - Route `GET /workspace` → `Admin\WorkspaceController@index`, name `workspace.index`, in the `auth` group.
  - `WorkspaceController::index(Request $request): \Inertia\Response` rendering component `Workspace/Index` with props: `providers` (`[{id,name}]` active, name-ordered), `selectedProviderId` (`int|null`), `date` (`string`, `Y-m-d`), `appointments` (`[{id, patient_id, patient_name, patient_age (int|null), type, status, start_time (ISO), end_time (ISO|null), notes (string|null), open_treatment_count (int), active_prescription_count (int)}]`, ordered by `start_time` then `id`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/WorkspaceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_the_workspace(): void
    {
        $this->get(route('workspace.index'))->assertRedirect(route('login'));
    }

    public function test_it_renders_the_workspace_with_defaults(): void
    {
        $this->actingUser();
        Provider::factory()->create(['name' => 'Dr. Alvarez', 'active' => true]);

        $response = $this->get(route('workspace.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Workspace/Index')
            ->where('date', now()->toDateString())
            ->where('selectedProviderId', null)
            ->has('providers', 1)
            ->has('appointments', 0)
        );
    }

    public function test_it_lists_the_target_days_appointments_ordered_by_start_time(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $later = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->addHours(2), 'end_time' => $day->clone()->addHours(2)->addMinutes(30)]);
        $sooner = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->addDay(), 'end_time' => $day->clone()->addDay()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->subDay(), 'end_time' => $day->clone()->subDay()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('appointments', 2)
            ->where('appointments.0.id', $sooner->id)
            ->where('appointments.1.id', $later->id)
        );
    }

    public function test_the_date_param_selects_that_day(): void
    {
        $this->actingUser();
        $target = now()->addDays(3)->startOfDay()->addHours(10);
        $onTarget = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $target->clone(), 'end_time' => $target->clone()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => now()->startOfDay()->addHours(10), 'end_time' => now()->startOfDay()->addHours(10)->addMinutes(30)]);

        $response = $this->get(route('workspace.index', ['date' => $target->toDateString()]));

        $response->assertInertia(fn ($page) => $page
            ->where('date', $target->toDateString())
            ->has('appointments', 1)
            ->where('appointments.0.id', $onTarget->id)
        );
    }

    public function test_the_provider_id_param_filters_to_that_provider(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $mine = Provider::factory()->create(['active' => true]);
        $theirs = Provider::factory()->create(['active' => true]);
        $a = Appointment::factory()->create(['provider_id' => $mine->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['provider_id' => $theirs->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index', ['provider_id' => $mine->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedProviderId', $mine->id)
            ->has('appointments', 1)
            ->where('appointments.0.id', $a->id)
        );
    }

    public function test_it_excludes_requested_cancelled_declined_and_no_show(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        foreach (['cancelled', 'declined', 'no_show'] as $i => $status) {
            Appointment::factory()->create(['status' => $status, 'start_time' => $day->clone()->addHours($i), 'end_time' => $day->clone()->addHours($i)->addMinutes(30)]);
        }
        Appointment::factory()->requested()->create(['preferred_date' => $day->toDateString()]);
        $shown = Appointment::factory()->create(['status' => 'completed', 'start_time' => $day->clone()->addHours(5), 'end_time' => $day->clone()->addHours(5)->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('appointments', 1)
            ->where('appointments.0.id', $shown->id)
        );
    }

    public function test_badge_counts_are_per_patient_and_only_count_open_active_rows(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);

        $busy = Patient::factory()->create();
        TreatmentPlanItem::factory()->count(2)->create(['patient_id' => $busy->id, 'status' => 'planned']);
        TreatmentPlanItem::factory()->create(['patient_id' => $busy->id, 'status' => 'completed']);
        Prescription::factory()->create(['patient_id' => $busy->id, 'status' => 'active']);
        Prescription::factory()->discontinued()->create(['patient_id' => $busy->id]);
        Appointment::factory()->create(['patient_id' => $busy->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);

        $fresh = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $fresh->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('appointments.0.patient_id', $busy->id)
            ->where('appointments.0.open_treatment_count', 2)
            ->where('appointments.0.active_prescription_count', 1)
            ->where('appointments.1.patient_id', $fresh->id)
            ->where('appointments.1.open_treatment_count', 0)
            ->where('appointments.1.active_prescription_count', 0)
        );
    }

    public function test_an_inactive_provider_is_absent_from_the_list_but_its_appointment_still_shows(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $inactive = Provider::factory()->create(['active' => false]);
        $appt = Appointment::factory()->create(['provider_id' => $inactive->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('providers', 0)
            ->has('appointments', 1)
            ->where('appointments.0.id', $appt->id)
        );
    }

    public function test_patient_age_is_an_integer_or_null(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $withDob = Patient::factory()->create(['date_of_birth' => now()->subYears(30)->subMonths(2)->toDateString()]);
        $noDob = Patient::factory()->create(['date_of_birth' => null]);
        Appointment::factory()->create(['patient_id' => $withDob->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['patient_id' => $noDob->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('appointments.0.patient_age', 30)
            ->where('appointments.1.patient_age', null)
        );
    }

    public function test_a_nonexistent_provider_id_is_rejected(): void
    {
        $this->actingUser();
        $this->get(route('workspace.index', ['provider_id' => 999999]))->assertSessionHasErrors('provider_id');
    }

    public function test_an_unparseable_date_is_rejected(): void
    {
        $this->actingUser();
        $this->get(route('workspace.index', ['date' => 'not-a-date']))->assertSessionHasErrors('date');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=WorkspaceTest`
Expected: FAIL — `Route [workspace.index] not defined`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/WorkspaceController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A per-provider clinical-prep view: one date's scheduled appointments
 * for the chosen provider (or all), each row carrying how much open
 * clinical work that patient has. Read-only — the workspace writes
 * nothing; clinical edits happen on /patients/{patient}.
 */
class WorkspaceController extends Controller
{
    /**
     * Appointment statuses a prep view cares about — identical to
     * QueueController::index(). 'requested' has no start_time, and
     * cancelled/declined/no_show are not part of preparing for the day.
     */
    private const SHOWN_STATUSES = ['scheduled', 'checked_in', 'in_treatment', 'completed'];

    /** Treatment-plan statuses that still represent work to do. */
    private const OPEN_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'exists:providers,id'],
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'])
            : Carbon::today();
        $providerId = $validated['provider_id'] ?? null;

        $appointments = Appointment::query()
            ->with('patient:id,first_name,last_name,date_of_birth')
            ->whereDate('start_time', $date)
            ->whereIn('status', self::SHOWN_STATUSES)
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $patientIds = $appointments->pluck('patient_id')->unique()->values();

        $openTreatments = TreatmentPlanItem::query()
            ->whereIn('patient_id', $patientIds)
            ->whereIn('status', self::OPEN_TREATMENT_STATUSES)
            ->selectRaw('patient_id, COUNT(*) as total')
            ->groupBy('patient_id')
            ->pluck('total', 'patient_id');

        $activePrescriptions = Prescription::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', 'active')
            ->selectRaw('patient_id, COUNT(*) as total')
            ->groupBy('patient_id')
            ->pluck('total', 'patient_id');

        return Inertia::render('Workspace/Index', [
            'providers' => Provider::where('active', true)->orderBy('name')->get(['id', 'name']),
            'selectedProviderId' => $providerId !== null ? (int) $providerId : null,
            'date' => $date->toDateString(),
            'appointments' => $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'patient_name' => $appointment->patient->full_name,
                'patient_age' => $appointment->patient->date_of_birth
                    ? (int) $appointment->patient->date_of_birth->diffInYears(now())
                    : null,
                'type' => $appointment->type,
                'status' => $appointment->status,
                'start_time' => $appointment->start_time->toIso8601String(),
                'end_time' => $appointment->end_time?->toIso8601String(),
                'notes' => $appointment->notes,
                'open_treatment_count' => (int) ($openTreatments[$appointment->patient_id] ?? 0),
                'active_prescription_count' => (int) ($activePrescriptions[$appointment->patient_id] ?? 0),
            ]),
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/web.php`:

Add alongside the other `Admin` controller imports (alphabetical — after `QueueController`, before `ToothConditionController`):
```php
use App\Http\Controllers\Admin\WorkspaceController;
```

Inside the `Route::middleware('auth')->group(...)`, immediately after the two `queue` routes:
```php
    Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=WorkspaceTest`
Expected: PASS (12 tests).

If `test_patient_age_is_an_integer_or_null` fails with an off-by-one or a
float, the cause is Carbon's `diffInYears` sign/precision — change the
controller line to `(int) abs($appointment->patient->date_of_birth->diffInYears(now()))` and re-run. Do not change the test's expected value (30 is correct for a DOB 30 years and 2 months ago).

- [ ] **Step 6: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass (pre-existing suite + 12 new).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/WorkspaceController.php routes/web.php tests/Feature/WorkspaceTest.php
git commit -m "Add WorkspaceController and the /workspace route"
```

---

## Task 2: `Workspace/Index.jsx` page + nav links + docs

**Files:**
- Create: `resources/js/Pages/Workspace/Index.jsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: props from Task 1's `Workspace/Index` render — `providers` (`[{id,name}]`), `selectedProviderId` (`int|null`), `date` (`string` `Y-m-d`), `appointments` (see Task 1 Produces for the row shape). Route `workspace.index`.
- Produces: no new backend interface. `Workspace/Index.jsx` default-exports the page component. Two nav links pointing at `route('workspace.index')`.

This task has no PHPUnit test (React view code; the data contract is covered by Task 1's Inertia assertions). Verify with `npm run build` and the full `artisan test` suite (the new page must not break the existing render tests).

- [ ] **Step 1: Create the page**

Create `resources/js/Pages/Workspace/Index.jsx`:

```jsx
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const STATUS_BADGE = {
    scheduled: 'bg-gray-100 text-gray-700 border-gray-300',
    checked_in: 'bg-blue-100 text-blue-800 border-blue-300',
    in_treatment: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    completed: 'bg-green-100 text-green-800 border-green-300',
};

function formatTimeRange(startIso, endIso) {
    const opts = { hour: 'numeric', minute: '2-digit' };
    const start = new Date(startIso).toLocaleTimeString(undefined, opts);
    if (!endIso) return start;
    const end = new Date(endIso).toLocaleTimeString(undefined, opts);
    return `${start}–${end}`;
}

function formatLongDate(ymd) {
    // ymd is 'YYYY-MM-DD'; parse as local, not UTC
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function shiftDate(ymd, days) {
    const [y, m, d] = ymd.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    dt.setDate(dt.getDate() + days);
    const pad = (n) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

function todayYmd() {
    const dt = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

function pluralise(n, word) {
    return `${n} ${word}${n === 1 ? '' : 's'}`;
}

export default function Index({ providers, selectedProviderId, date, appointments }) {
    function navigate(params) {
        router.get(
            route('workspace.index'),
            { provider_id: selectedProviderId ?? undefined, date, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const providerLabel = selectedProviderId
        ? providers.find((p) => p.id === selectedProviderId)?.name ?? 'that provider'
        : 'all providers';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Workspace</h2>}>
            <Head title="Workspace" />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <select
                        className="border rounded px-3 py-2 text-sm"
                        value={selectedProviderId ?? ''}
                        onChange={(e) => navigate({ provider_id: e.target.value || undefined })}
                    >
                        <option value="">All providers</option>
                        {providers.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                    </select>

                    <input
                        type="date"
                        className="border rounded px-3 py-2 text-sm"
                        value={date}
                        onChange={(e) => e.target.value && navigate({ date: e.target.value })}
                    />

                    <div className="flex gap-1">
                        <button type="button" onClick={() => navigate({ date: shiftDate(date, -1) })} className="rounded border px-2 py-2 text-sm">
                            ‹ Prev
                        </button>
                        <button type="button" onClick={() => navigate({ date: todayYmd() })} className="rounded border px-3 py-2 text-sm">
                            Today
                        </button>
                        <button type="button" onClick={() => navigate({ date: shiftDate(date, 1) })} className="rounded border px-2 py-2 text-sm">
                            Next ›
                        </button>
                    </div>
                </div>

                <h3 className="mb-3 text-sm font-semibold text-gray-500">{formatLongDate(date)}</h3>

                <div className="space-y-2">
                    {appointments.map((appt) => (
                        <div key={appt.id} className="rounded border bg-white p-4 text-sm shadow-sm">
                            <div className="flex flex-wrap items-center gap-2 text-gray-500">
                                <span className="font-medium text-gray-900">{formatTimeRange(appt.start_time, appt.end_time)}</span>
                                <span className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[appt.status] ?? 'bg-gray-100 text-gray-700 border-gray-300'}`}>
                                    {appt.status.replace('_', ' ')}
                                </span>
                                {appt.type && <span>{appt.type}</span>}
                            </div>

                            <div className="mt-1">
                                <Link href={`/patients/${appt.patient_id}`} className="font-medium text-blue-600">
                                    {appt.patient_name}
                                </Link>
                                {appt.patient_age !== null && <span className="text-gray-500"> ({appt.patient_age})</span>}
                            </div>

                            {(appt.open_treatment_count > 0 || appt.active_prescription_count > 0) && (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {appt.open_treatment_count > 0 && (
                                        <span className="inline-block rounded border border-amber-300 bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                            {pluralise(appt.open_treatment_count, 'open treatment')}
                                        </span>
                                    )}
                                    {appt.active_prescription_count > 0 && (
                                        <span className="inline-block rounded border border-blue-300 bg-blue-100 px-2 py-0.5 text-xs text-blue-800">
                                            {pluralise(appt.active_prescription_count, 'active Rx')}
                                        </span>
                                    )}
                                </div>
                            )}

                            {appt.notes && <p className="mt-2 text-gray-600">Notes: {appt.notes}</p>}
                        </div>
                    ))}

                    {appointments.length === 0 && (
                        <div className="rounded border bg-white p-4 text-sm text-gray-500 shadow-sm">
                            No appointments for {providerLabel} on {formatLongDate(date)}.
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Add the nav links**

Modify `resources/js/Layouts/AuthenticatedLayout.jsx`:

In the desktop nav (`hidden ... sm:flex` block), immediately after the `queue.index` `<NavLink>` (the one whose text is "Queue", ends `</NavLink>` around line 56) and before the `inquiries.index` `<NavLink>`:
```jsx
                                <NavLink
                                    href={route('workspace.index')}
                                    active={route().current('workspace.index')}
                                >
                                    Workspace
                                </NavLink>
```

In the responsive/mobile nav, immediately after the `queue.index` `<ResponsiveNavLink>` (text "Queue", around line 190) and before the `inquiries.index` `<ResponsiveNavLink>`:
```jsx
                        <ResponsiveNavLink
                            href={route('workspace.index')}
                            active={route().current('workspace.index')}
                        >
                            Workspace
                        </ResponsiveNavLink>
```

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Expected: succeeds, no "X is not defined" errors.

Dev smoke (`"$HOME/.config/herd/bin/php.bat" artisan serve` + `npm run dev`) — optional, for a human:
1. `/workspace` → controls row + today's date heading + either rows or the empty state.
2. Pick a provider → list narrows; the `<select>` keeps the selection; URL gains `?provider_id=`.
3. `‹ Prev` / `Next ›` / `Today` move the day; date input reflects it.
4. A row with pending work shows amber/blue badges; click the patient name → lands on `/patients/{id}`.
5. The "Workspace" nav link is present (desktop + mobile) and highlights on the page.

- [ ] **Step 4: Update `CLAUDE.md`**

Modify `CLAUDE.md` — under "Planning workflow" → "Shipped so far", after the "Phase 6, sub-project 4" bullet, add:

```markdown
- **Phase 6, sub-project 5** — the dentist workspace, specced at
  `docs/superpowers/specs/2026-08-29-dentist-workspace-design.md` — a
  `/workspace` page (`Admin\WorkspaceController@index`, read-only, no
  model/migration) showing one date's `scheduled`/`checked_in`/
  `in_treatment`/`completed` appointments for a chosen provider (or all
  active), ordered by `start_time`. Each row links to
  `/patients/{patient}` and carries badges for that patient's open
  treatment-plan items (`planned`/`scheduled`/`in_progress`) and active
  prescriptions. Provider `<select>` + date input + prev/today/next,
  each re-issuing an Inertia `GET` with `preserveState`. Because the app
  has no roles and no `Provider`↔`User` link, this is a shared view any
  staff member drives — not auto-scoped to a logged-in dentist (that
  needs auth work, deferred). No polling, no inline clinical editing.
```

- [ ] **Step 5: Run the full suite**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: all pass (pre-existing + the 12 `WorkspaceTest` cases).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Workspace/Index.jsx resources/js/Layouts/AuthenticatedLayout.jsx CLAUDE.md
git commit -m "Add the Workspace page with per-provider day view and clinical badges"
```

---

## Self-Review

**1. Spec coverage:**

| Spec section | Task |
|---|---|
| `GET /workspace` route, name `workspace.index`, auth group | Task 1 |
| `WorkspaceController::index` — validate `provider_id`/`date` | Task 1 step 3 |
| `date` defaults to today; `provider_id` nullable = "all" | Task 1 (`test_it_renders_the_workspace_with_defaults`) |
| Appointment query: `whereDate('start_time')`, status set matches Queue, optional provider filter, order `start_time` then `id`, eager-load patient | Task 1 step 3 + `test_it_lists...`, `test_it_excludes...` |
| Badge counts: grouped queries, open-treatment statuses, active prescriptions, per-patient, no leak | Task 1 step 3 + `test_badge_counts...` |
| Props: `providers` (active, ordered), `selectedProviderId` (int\|null), `date` (Y-m-d), `appointments` (row shape incl. `patient_age`) | Task 1 step 3 + render/age tests |
| Inactive provider absent from picker but its appt shown | Task 1 (`test_an_inactive_provider...`) |
| Validation errors for bad `provider_id` / `date` | Task 1 (last two tests) |
| Page: provider `<select>` ("All providers" + each), date input, prev/today/next, `router.get` w/ `preserveState` | Task 2 step 1 |
| Rows: time range, status badge (local colour map), type, patient `Link` + age, amber/blue count badges only when >0 & pluralised, notes line | Task 2 step 1 |
| Friendly date heading; empty state naming provider + date | Task 2 step 1 |
| Nav link (desktop + responsive) after "Queue" | Task 2 step 2 |
| CLAUDE.md "sub-project 5" bullet | Task 2 step 4 |
| Out-of-scope (no model/migration/factory, no inline editing, no polling, no auth) | Honoured — no task creates any of these |

**2. Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N". Every code step has literal code. Task 2's dev-smoke is explicitly optional-for-a-human; the binding verification is `npm run build` + full `artisan test`.

**3. Type consistency:**
- Prop names identical across Task 1 (`Inertia::render` array) and Task 2 (`Index({ providers, selectedProviderId, date, appointments })`): `providers`, `selectedProviderId`, `date`, `appointments`. ✓
- Row keys in Task 1's `map` (`id`, `patient_id`, `patient_name`, `patient_age`, `type`, `status`, `start_time`, `end_time`, `notes`, `open_treatment_count`, `active_prescription_count`) all read in Task 2 (`appt.patient_id`, `appt.patient_age`, `appt.open_treatment_count`, etc.). ✓
- `selectedProviderId` is `int|null` from Task 1; Task 2 compares `providers.find((p) => p.id === selectedProviderId)` (both numbers) and uses `selectedProviderId ?? ''` for the `<select>` value + `selectedProviderId ?? undefined` in params. ✓
- Route name `workspace.index` identical in Task 1 route, Task 1 tests, Task 2 `router.get` / nav links. ✓
- Status-badge keys (`scheduled`/`checked_in`/`in_treatment`/`completed`) match `SHOWN_STATUSES`. ✓
