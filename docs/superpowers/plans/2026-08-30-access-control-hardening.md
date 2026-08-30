# Access Control & Authentication Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the gap between "authenticated" and "clinic staff" — gate registration behind a clinic-wide code, make email verification actually enforce, and fix the surrounding authentication weaknesses (enumeration, throttling, password policy, session hijack persistence) that make a staff account cheap to take over once it exists.

**Architecture:** No new tables, no new controllers. Every change modifies an existing Breeze auth file, `routes/web.php`, `routes/auth.php`, `config/clinic.php`, or `AppServiceProvider`. The riskiest change — enforcing `verified` — is de-risked by the fact `UserFactory` already sets `email_verified_at => now()`, so all 334 existing tests stay green; only the auth/profile tests that construct passwords or unverified users need updates, made in the tasks that touch them.

**Tech Stack:** Laravel 12, Inertia 2, React 18, Tailwind 3, MariaDB (tests: `dentalcrm_testing`), Herd PHP 8.4. PHPUnit feature tests with `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-08-30-access-control-hardening-design.md`

## Global Constraints

- Run PHP tooling via `"$HOME/.config/herd/bin/php.bat"` (e.g. `"$HOME/.config/herd/bin/php.bat" artisan test`). `npm` is on PATH.
- Tests are **flat**: `tests/Feature/<Name>Test.php` or `tests/Feature/Auth/<Name>Test.php` (the one existing subdirectory) — no new subdirectories.
- **No RBAC, still.** Every authenticated, verified user remains an equal front-desk staff member. Nothing here scopes data by user.
- **No external network calls.** `Rules\Password::uncompromised()` is NOT used (it calls the HaveIBeenPwned API). A composition rule (`min(12)->letters()->numbers()`) is used instead.
- **No new mail transport.** Email verification uses Laravel's built-in `VerifyEmail` notification over the existing `log` mailer in dev / `array` in tests.
- `.env.example` must keep working for a fresh `composer setup` — every new env key gets a working default there.
- Clean-codebase rules: no `dd()`/`console.log`/`var_dump()`, no unused imports, no commented-out code.
- Commits carry **NO** `Co-Authored-By` trailer. Short imperative subjects. One commit per task.
- After any task that changes a `.jsx` file, run `npm run build` and confirm it succeeds (the Vite manifest is needed for feature tests that render the root blade).

---

### Task 1: Registration is gated by a clinic registration code

**Files:**
- Modify: `config/clinic.php`
- Modify: `.env.example`
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php`
- Modify: `resources/js/Pages/Auth/Register.jsx`
- Modify: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `config('clinic.registration_code')` — a plain string read by `RegisteredUserController`. Later tasks don't depend on this.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `tests/Feature/Auth/RegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['clinic.registration_code' => 'harborview-2026']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_screen_is_unreachable_when_no_code_is_configured(): void
    {
        config(['clinic.registration_code' => null]);

        $this->get('/register')->assertStatus(403);
        $this->post('/register', ['name' => 'Test User'])->assertStatus(403);
    }

    public function test_new_users_can_register_with_the_correct_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'registration_code' => 'harborview-2026',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_rejected_with_a_wrong_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'registration_code' => 'wrong-code',
        ]);

        $response->assertSessionHasErrors('registration_code');
        $this->assertGuest();
    }

    public function test_registration_is_rejected_with_a_missing_code(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertSessionHasErrors('registration_code');
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=RegistrationTest`
Expected: FAIL — the 403 test fails because `/register` currently always returns 200/redirects, and the wrong/missing-code tests fail because registration currently succeeds regardless of any `registration_code` field.

- [ ] **Step 3: Add the config key**

In `config/clinic.php`, add after the `'contact_email'` line (before the closing `];`):

```php

    /*
     * The shared code a new staff member must supply at /register.
     * Empty or unset disables self-registration entirely — GET and POST
     * /register both 403. A real deployment sets this to a strong value,
     * shares it out of band with incoming staff, and may blank it again
     * once onboarding is finished.
     */
    'registration_code' => env('REGISTRATION_CODE'),
```

- [ ] **Step 4: Add the env default**

In `.env.example`, add after the `MAIL_FROM_NAME="${APP_NAME}"` line:

```
# Shared code required at /register. Blank disables self-registration.
REGISTRATION_CODE=harborview-dev
```

- [ ] **Step 5: Gate the controller**

Replace the full contents of `app/Http/Controllers/Auth/RegisteredUserController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $this->abortIfRegistrationClosed();

        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortIfRegistrationClosed();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'registration_code' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('clinic.registration_code'), $request->string('registration_code')->value())) {
            throw ValidationException::withMessages([
                'registration_code' => 'That registration code is not correct.',
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /**
     * Self-registration is a deployment-time choice, not a permanent
     * feature — blanking the config value turns it off without a
     * deploy of route changes.
     *
     * @throws HttpException
     */
    protected function abortIfRegistrationClosed(): void
    {
        abort_if(blank(config('clinic.registration_code')), 403);
    }
}
```

- [ ] **Step 6: Add the registration-code field to the form**

In `resources/js/Pages/Auth/Register.jsx`, add `registration_code: ''` to the `useForm` initial data:

```jsx
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        registration_code: '',
    });
```

Add a new field block directly above the `mt-4 flex items-center justify-end` closing div (i.e. after the "Confirm Password" block, before the links/submit row):

```jsx
                <div className="mt-4">
                    <InputLabel
                        htmlFor="registration_code"
                        value="Clinic registration code"
                    />

                    <TextInput
                        id="registration_code"
                        name="registration_code"
                        value={data.registration_code}
                        className="mt-1 block w-full"
                        onChange={(e) =>
                            setData('registration_code', e.target.value)
                        }
                        required
                    />

                    <p className="mt-1 text-sm text-gray-500">
                        Ask the practice manager for this.
                    </p>

                    <InputError
                        message={errors.registration_code}
                        className="mt-2"
                    />
                </div>
```

- [ ] **Step 7: Build the frontend**

Run: `npm run build`
Expected: succeeds with no errors.

- [ ] **Step 8: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=RegistrationTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. (No other test hits `/register`.)

- [ ] **Step 10: Commit**

```bash
git add config/clinic.php .env.example app/Http/Controllers/Auth/RegisteredUserController.php resources/js/Pages/Auth/Register.jsx tests/Feature/Auth/RegistrationTest.php
git commit -m "Gate registration behind a clinic registration code"
```

---

### Task 2: Email verification is enforced on every staff route

**Files:**
- Modify: `app/Models/User.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/StaffRouteProtectionTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `App\Models\User implements MustVerifyEmail`. `routes/web.php` has two authenticated groups instead of one: `Route::middleware('auth')` (profile only) and `Route::middleware(['auth', 'verified'])` (dashboard + every staff feature). Later tasks (6, 7) add routes to `routes/auth.php`, not this file, so there's no conflict.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StaffRouteProtectionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A representative, parameter-free route from every controller behind
     * the ['auth', 'verified'] group. Every route in that group shares the
     * same middleware declaration, so this sample proves the group applies
     * without needing a real model for every {id} route.
     */
    private const STAFF_ROUTES = [
        ['GET', '/dashboard'],
        ['GET', '/providers'],
        ['POST', '/providers'],
        ['GET', '/patients'],
        ['POST', '/patients'],
        ['GET', '/appointments'],
        ['GET', '/appointments/events'],
        ['POST', '/appointments'],
        ['GET', '/queue'],
        ['POST', '/queue/walk-ins'],
        ['GET', '/workspace'],
        ['GET', '/reports'],
        ['GET', '/invoices'],
        ['POST', '/invoices'],
        ['GET', '/inventory'],
        ['POST', '/inventory'],
        ['GET', '/inquiries'],
    ];

    public function test_a_guest_is_redirected_to_login_from_every_staff_route(): void
    {
        foreach (self::STAFF_ROUTES as [$method, $uri]) {
            $this->call($method, $uri)->assertRedirect(route('login'));
        }
    }

    public function test_an_unverified_user_is_redirected_to_the_verification_notice_from_every_staff_route(): void
    {
        $user = User::factory()->unverified()->create();

        foreach (self::STAFF_ROUTES as [$method, $uri]) {
            $this->actingAs($user)->call($method, $uri)
                ->assertRedirect(route('verification.notice'));
        }
    }

    public function test_a_verified_staff_member_can_reach_every_index_page(): void
    {
        $user = User::factory()->create(); // verified by factory default

        $getOnlyIndexes = [
            '/dashboard', '/providers', '/patients', '/appointments',
            '/appointments/events', '/queue', '/workspace', '/reports',
            '/invoices', '/inventory', '/inquiries',
        ];

        foreach ($getOnlyIndexes as $uri) {
            $this->actingAs($user)->get($uri)->assertOk();
        }
    }

    public function test_profile_stays_reachable_while_unverified(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=StaffRouteProtectionTest`
Expected: FAIL — the unverified-redirect test fails because `verified` is currently a no-op (every staff route returns 200/302-to-nowhere-relevant instead of redirecting to `verification.notice`).

- [ ] **Step 3: Implement `MustVerifyEmail` on `User`**

In `app/Models/User.php`, uncomment the import and add the interface:

```php
<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
```

(Only the `use` block and the `class` line change; the rest of the file is untouched.)

- [ ] **Step 4: Split the staff route group in `routes/web.php`**

Replace the block from `Route::get('/dashboard', ...)` through the `Route::middleware('auth')->group(function () { ... });` closing `});` with:

```php
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('providers', ProviderController::class)
        ->except(['create', 'edit', 'show']);

    Route::resource('patients', PatientController::class)
        ->except(['create', 'edit']);

    Route::post('/patients/{patient}/dental-records', [DentalRecordController::class, 'store'])
        ->name('dental-records.store');

    Route::post('/patients/{patient}/tooth-conditions', [ToothConditionController::class, 'store'])
        ->name('tooth-conditions.store');

    Route::post('/patients/{patient}/treatment-plan-items', [TreatmentPlanItemController::class, 'store'])
        ->name('treatment-plan-items.store');

    Route::patch('/patients/{patient}/treatment-plan-items/{treatmentPlanItem}', [TreatmentPlanItemController::class, 'update'])
        ->name('treatment-plan-items.update');

    Route::post('/patients/{patient}/prescriptions', [PrescriptionController::class, 'store'])
        ->name('prescriptions.store');

    Route::patch('/patients/{patient}/prescriptions/{prescription}', [PrescriptionController::class, 'update'])
        ->name('prescriptions.update');

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/events', [AppointmentController::class, 'events'])->name('appointments.events');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');

    Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
    Route::post('/queue/walk-ins', [QueueController::class, 'storeWalkIn'])->name('queue.walkins.store');

    Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace.index');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoice-payments.store');

    Route::get('/inventory', [InventoryItemController::class, 'index'])->name('inventory.index');
    Route::post('/inventory', [InventoryItemController::class, 'store'])->name('inventory.store');
    Route::patch('/inventory/{inventoryItem}', [InventoryItemController::class, 'update'])->name('inventory.update');
    Route::get('/inventory/{inventoryItem}', [InventoryItemController::class, 'show'])->name('inventory.show');
    Route::post('/inventory/{inventoryItem}/movements', [StockMovementController::class, 'store'])->name('inventory-movements.store');

    Route::get('/inquiries', [AdminInquiryController::class, 'index'])->name('inquiries.index');
    Route::patch('/inquiries/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
});
```

Note what changed from the current file: `/profile` (`profile.edit`/`profile.update`/`profile.destroy`) now sits in its **own** `auth`-only group — it must stay reachable to an unverified user so they can see and use the "resend verification email" banner already built into `Profile/Edit.jsx`. Every other route moves into the new `['auth', 'verified']` group, including `/dashboard`, which drops its old inline `->middleware(['auth', 'verified'])` since the group now supplies both.

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=StaffRouteProtectionTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. Every factory-created `User` is verified by default (`UserFactory::definition()` sets `email_verified_at => now()`), so no other test is affected. `tests/Feature/Auth/EmailVerificationTest.php`'s existing tests should now be more meaningful (they already exercise `MustVerifyEmail`-dependent behavior against an interface-conformant model) and continue to pass unchanged.

- [ ] **Step 7: Commit**

```bash
git add app/Models/User.php routes/web.php tests/Feature/StaffRouteProtectionTest.php
git commit -m "Enforce email verification on every staff route"
```

---

### Task 3: `/forgot-password` stops confirming who exists

**Files:**
- Modify: `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- Modify: `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Auth/PasswordResetTest.php` (inside the class, after `test_reset_password_link_screen_can_be_rendered`):

```php
    public function test_the_response_is_identical_for_a_known_and_an_unknown_email(): void
    {
        $user = User::factory()->create();

        $knownResponse = $this->post('/forgot-password', ['email' => $user->email]);
        $unknownResponse = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $knownResponse->assertSessionHasNoErrors();
        $unknownResponse->assertSessionHasNoErrors();

        $knownResponse->assertSessionHas('status', __(Password::RESET_LINK_SENT));
        $unknownResponse->assertSessionHas('status', __(Password::RESET_LINK_SENT));
    }
```

Add `use App\Models\User;` (already present in this file) and `use Illuminate\Support\Facades\Password;` to the top of `tests/Feature/Auth/PasswordResetTest.php` if not already imported — `Password` is not currently imported there.

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordResetTest`
Expected: FAIL — the unknown-email request currently returns a `422`/session error on the `email` field, not a `status` flash.

- [ ] **Step 3: Make the response uniform**

Replace the `store()` method in `app/Http/Controllers/Auth/PasswordResetLinkController.php`:

```php
    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // The response is identical whether or not the email is registered
        // (§A3) — the real broker status is logged, not surfaced, so a
        // genuine mail failure is still diagnosable server-side.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status !== Password::RESET_LINK_SENT) {
            Log::info('Password reset link not sent.', ['status' => $status]);
        }

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
```

Add `use Illuminate\Support\Facades\Log;` to the imports, and remove the now-unused `use Illuminate\Validation\ValidationException;` import (the method no longer throws).

- [ ] **Step 4: Add the route throttle**

In `routes/auth.php`, change:

```php
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');
```

to:

```php
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordResetTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/PasswordResetLinkController.php routes/auth.php tests/Feature/Auth/PasswordResetTest.php
git commit -m "Stop confirming whether an email is registered on forgot-password"
```

---

### Task 4: Login gains a per-IP throttle bucket

**Files:**
- Modify: `app/Http/Requests/Auth/LoginRequest.php`
- Modify: `tests/Feature/Auth/AuthenticationTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Auth/AuthenticationTest.php` (inside the class):

```php
    public function test_a_single_ip_is_capped_at_twenty_failed_attempts_across_distinct_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', [
                'email' => "attempt{$i}@example.com",
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'attempt20@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }
```

The exact message (`Illuminate\Translation\lang\en\auth.php`) is `'Too many login attempts. Please try again in :seconds seconds.'` — confirmed by reading the vendor translation file rather than assumed.

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=AuthenticationTest`
Expected: FAIL — 21 distinct emails from one IP currently never trips any limiter (each has its own `email|ip` bucket), so the 21st attempt gets the ordinary "these credentials do not match" error instead of a throttle message.

- [ ] **Step 3: Add the per-IP bucket**

Replace `app/Http/Requests/Auth/LoginRequest.php`'s `authenticate()` and `ensureIsNotRateLimited()` methods, and add a second throttle-key method:

```php
    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * Two independent buckets: five attempts per email+IP (unchanged), and
     * twenty attempts per IP regardless of which email is targeted — the
     * email+IP bucket alone lets one IP spray many accounts without ever
     * tripping a single account's limiter (§A4).
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $seconds = null;

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
        }

        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), 20)) {
            $seconds = max($seconds ?? 0, RateLimiter::availableIn($this->ipThrottleKey()));
        }

        if ($seconds === null) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Get the IP-only rate limiting throttle key for the request.
     *
     * Not cleared on success — a shared-IP clinic where one staff member
     * logs in successfully must not reset an attacker's per-IP budget.
     */
    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }
```

Note the per-IP bucket is hit with an explicit 60-second decay (`RateLimiter::hit($key, 60)`) while the existing per-email+IP bucket keeps its implicit default decay — both are independent and both are checked before every attempt.

- [ ] **Step 4: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=AuthenticationTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Auth/LoginRequest.php tests/Feature/Auth/AuthenticationTest.php
git commit -m "Add a per-IP login throttle bucket alongside the per-account one"
```

---

### Task 5: A real password policy

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Feature/Auth/PasswordResetTest.php`
- Modify: `tests/Feature/Auth/PasswordUpdateTest.php`
- Create: `tests/Feature/Auth/PasswordPolicyTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks. (Task 6 changes `PasswordController::update()` again but doesn't depend on anything defined here beyond the policy already being active.)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/PasswordPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_password_under_twelve_characters(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Short1234',
            'password_confirmation' => 'Short1234',
            'registration_code' => 'test-code',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_rejects_a_password_with_no_digit(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'NoDigitsHere',
            'password_confirmation' => 'NoDigitsHere',
            'registration_code' => 'test-code',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_accepts_a_twelve_character_password_with_letters_and_numbers(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'ValidPass123',
            'password_confirmation' => 'ValidPass123',
            'registration_code' => 'test-code',
        ]);

        $this->assertAuthenticated();
    }

    public function test_profile_password_update_enforces_the_same_policy(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordPolicyTest`
Expected: FAIL — all three rejection/acceptance boundaries are wrong under the current `min(8)`-only policy (`Short1234` and `NoDigitsHere` both currently pass; `short1` also currently passes since it's ≥8 chars... actually `short1` is 6 chars so it already fails today, but for the wrong reason — length, not the intended digit/letter composition path — the important failing assertions are the two `test_registration_rejects_*` cases).

- [ ] **Step 3: Register the policy**

Replace `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // No ->uncompromised(): that rule calls the HaveIBeenPwned API over
        // the network on every password set, which this app avoids
        // everywhere else ("nothing is transmitted anywhere"). A deployer
        // who accepts that outbound call can add it here.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
    }
}
```

- [ ] **Step 4: Fix existing tests that use a non-conforming new password**

In `tests/Feature/Auth/PasswordResetTest.php`, `test_password_can_be_reset_with_valid_token` currently resets to `'password'` (8 chars, no digit — now invalid). Change both occurrences of `'password'` used as the **new** password (not the user's original login password) to `'NewSecurePass123'`:

```php
            Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
                $response = $this->post('/reset-password', [
                    'token' => $notification->token,
                    'email' => $user->email,
                    'password' => 'NewSecurePass123',
                    'password_confirmation' => 'NewSecurePass123',
                ]);
```

In `tests/Feature/Auth/PasswordUpdateTest.php`, `test_password_can_be_updated` sets the new password to `'new-password'` (no digit — now invalid). Update the whole test:

```php
    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewSecurePass123',
                'password_confirmation' => 'NewSecurePass123',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('NewSecurePass123', $user->refresh()->password));
    }
```

`test_correct_password_must_be_provided_to_update_password` is untouched — it already fails for a different reason (wrong current password) before the new-password policy is ever checked.

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordPolicyTest`
Expected: PASS.

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordResetTest`
Expected: PASS.

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordUpdateTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. No other test submits a new password through register/reset/profile-password-update, so nothing else is affected — logging in with the factory's `'password'` is unaffected, since the policy applies only when a password is written, not when one is checked.

- [ ] **Step 7: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Auth/PasswordPolicyTest.php tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/PasswordUpdateTest.php
git commit -m "Require a 12-character password with letters and numbers"
```

---

### Task 6: Password changes end other sessions

**Files:**
- Modify: `app/Http/Controllers/Auth/PasswordController.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Feature/Auth/PasswordUpdateTest.php`

**Interfaces:**
- Consumes: the `Password::defaults()` policy from Task 5 (the test's new passwords must conform — they already do, reusing `'NewSecurePass123'`-shaped values).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Auth/PasswordUpdateTest.php` (inside the class):

```php
    public function test_updating_the_password_rotates_the_remember_token(): void
    {
        $user = User::factory()->create();
        $originalToken = $user->remember_token;

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'NewSecurePass123',
            'password_confirmation' => 'NewSecurePass123',
        ]);

        $this->assertNotSame($originalToken, $user->fresh()->remember_token);
    }

    public function test_a_sibling_session_is_logged_out_after_a_password_change(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldSecurePass123')]);

        // Prime a second, sibling session the way AuthenticateSession does
        // on a real first request, then leave it untouched while the
        // password changes elsewhere.
        $this->actingAs($user)
            ->withSession(['password_hash_web' => Hash::make('OldSecurePass123')])
            ->get('/dashboard')
            ->assertOk();

        $user->forceFill(['password' => Hash::make('NewSecurePass456')])->save();

        $response = $this->actingAs($user)
            ->withSession(['password_hash_web' => Hash::make('OldSecurePass123')])
            ->get('/dashboard');

        $response->assertRedirect(route('login'));
    }
```

Add `use Illuminate\Support\Facades\Hash;` to the top of the file if not already present (it already is, per the existing `test_password_can_be_updated` test).

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordUpdateTest`
Expected: FAIL — `remember_token` is currently untouched by a password change, and no `AuthenticateSession` middleware exists yet, so the sibling-session request currently returns 200, not a redirect.

- [ ] **Step 3: Rotate the remember token on password change**

Replace `app/Http/Controllers/Auth/PasswordController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // AuthenticateSession compares this request's session against the
        // user's current password hash on every subsequent request. Without
        // refreshing it here, the very next request from THIS browser would
        // also be logged out — only a stolen sibling session should be.
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $request->user()->getAuthPassword()
        );

        return back();
    }
}
```

- [ ] **Step 4: Register `AuthenticateSession`**

In `bootstrap/app.php`, add the middleware to the `web` group append list:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        //
    })
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=PasswordUpdateTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. `AuthenticateSession` is transparent for every other test: on the first authenticated request within a test it finds no `password_hash_web` session key and silently stores one, since no other test changes a user's password mid-test.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/PasswordController.php bootstrap/app.php tests/Feature/Auth/PasswordUpdateTest.php
git commit -m "End other sessions when a password changes"
```

---

### Task 7: Changing the account email requires the current password

**Files:**
- Modify: `app/Http/Requests/ProfileUpdateRequest.php`
- Modify: `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.jsx`
- Modify: `tests/Feature/ProfileTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ProfileTest.php` (inside the class):

```php
    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.com',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_correct_current_password_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.com',
                'current_password' => 'password',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('new-address@example.com', $user->fresh()->email);
    }

    public function test_changing_only_the_name_does_not_require_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'A New Name',
                'email' => $user->email,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('A New Name', $user->fresh()->name);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ProfileTest`
Expected: FAIL — `test_changing_the_email_requires_the_current_password` fails because the email change currently succeeds with no password at all.

- [ ] **Step 3: Add the conditional rule**

Replace `app/Http/Requests/ProfileUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Only required when the email is actually changing — the form
            // always submits both fields, so a plain name edit must not be
            // blocked on a password the user has no reason to enter.
            'current_password' => [
                Rule::requiredIf(fn () => $this->input('email') !== $this->user()->email),
                'current_password',
            ],
        ];
    }
}
```

- [ ] **Step 4: Add the field to the profile form**

In `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.jsx`, add `current_password: ''` to the `useForm` initial data:

```jsx
    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            current_password: '',
        });
```

Add a field shown only while the email differs from the loaded user's original email — insert it directly after the email `<div>` block (before the `{mustVerifyEmail && ...}` block):

```jsx
                {data.email !== user.email && (
                    <div>
                        <InputLabel
                            htmlFor="current_password"
                            value="Current password"
                        />

                        <TextInput
                            id="current_password"
                            type="password"
                            className="mt-1 block w-full"
                            value={data.current_password}
                            onChange={(e) =>
                                setData('current_password', e.target.value)
                            }
                            autoComplete="current-password"
                        />

                        <p className="mt-1 text-sm text-gray-500">
                            Required to change your email address.
                        </p>

                        <InputError
                            className="mt-2"
                            message={errors.current_password}
                        />
                    </div>
                )}
```

- [ ] **Step 5: Build the frontend**

Run: `npm run build`
Expected: succeeds with no errors.

- [ ] **Step 6: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=ProfileTest`
Expected: PASS.

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. The existing `test_profile_information_can_be_updated` and `test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged` tests in `ProfileTest.php` both change the email without a `current_password` field — check both and add `'current_password' => 'password'` to `test_profile_information_can_be_updated`'s request payload (it changes the email to `'test@example.com'`, which differs from the factory-generated original). `test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged` submits the user's own unchanged email, so it needs no change — `current_password` isn't required when the email isn't changing.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/ProfileUpdateRequest.php resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.jsx tests/Feature/ProfileTest.php
git commit -m "Require the current password to change the account email"
```

---

### Task 8: Every auth endpoint is throttled

**Files:**
- Modify: `routes/auth.php`
- Create: `tests/Feature/Auth/AuthEndpointThrottleTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (Task 3 already added `throttle:6,1` to `forgot-password`; this task covers the remaining four).
- Produces: nothing consumed by later tasks. This is the last task in the plan.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/AuthEndpointThrottleTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthEndpointThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_throttled_after_six_requests_per_minute(): void
    {
        config(['clinic.registration_code' => 'test-code']);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', ['email' => "user{$i}@example.com"]);
        }

        $this->post('/register', ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }

    public function test_reset_password_is_throttled_after_six_requests_per_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', ['email' => "user{$i}@example.com"]);
        }

        $this->post('/reset-password', ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }

    public function test_confirm_password_is_throttled_after_six_requests_per_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong']);
        }

        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_password_update_is_throttled_after_six_requests_per_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->put('/password', ['current_password' => 'wrong']);
        }

        $this->actingAs($user)->put('/password', ['current_password' => 'wrong'])
            ->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=AuthEndpointThrottleTest`
Expected: FAIL — all four routes currently accept unlimited requests, so the 7th request in each test returns a normal validation response, not `429`.

- [ ] **Step 3: Add the throttles**

In `routes/auth.php`, add `->middleware('throttle:6,1')` to four routes. The `guest` group's `register` and `reset-password`:

```php
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');
```

```php
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
```

And the `auth` group's `confirm-password` and `password.update`:

```php
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test --filter=AuthEndpointThrottleTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `"$HOME/.config/herd/bin/php.bat" artisan test`
Expected: PASS. No existing test sends more than six requests to any of these four routes in a single test.

- [ ] **Step 6: Commit**

```bash
git add routes/auth.php tests/Feature/Auth/AuthEndpointThrottleTest.php
git commit -m "Throttle register, reset-password, confirm-password, and password update"
```

---

## Plan self-review notes

- **Spec coverage:** all 8 numbered findings (A1–A8) map one-to-one onto Tasks 1–8. `DemoSeeder`'s throwaway staff user needs no change — `UserFactory` already sets `email_verified_at => now()`, confirmed by reading the factory before writing this plan.
- **A real bug caught during planning, not in the spec as written:** the spec's §2 says "the staff group at `routes/web.php:57` becomes `Route::middleware(['auth', 'verified'])`" but that same group currently also contains `/profile`, and §2 separately requires `/profile` to stay reachable while unverified. Task 2 resolves this by splitting the group in two — `auth`-only for `/profile`, `['auth', 'verified']` for everything else — rather than applying `verified` to the literal existing group, which would have locked an unverified user out of the only page that can resend their verification email.
- **Type/name consistency:** `config('clinic.registration_code')` (Task 1) is referenced nowhere else. `MustVerifyEmail` (Task 2) is consumed only by the framework's own `verified` middleware and `ProfileController::edit()`'s existing `instanceof` check — no plan task needs to change `ProfileController`. `ipThrottleKey()` (Task 4) is new and used only within `LoginRequest`. `password_hash_web` (Task 6) is the framework's own session key name (`'password_hash_'.$guard`), not an app-defined identifier.
