# DentalCRM — Phase 8 sub-project 1: Access control & authentication hardening — Design

Status: draft, 2026-08-30.

## Purpose

Phase 8 of `docs/PLATFORM_VISION.md` is "Hardening" — security review,
accessibility, performance, responsive, cleanup, testing, docs. This is
the first of three security sub-projects, and it addresses the single
worst finding in the whole review:

> An anonymous visitor can `POST /register`, is logged in immediately,
> and then reads and mutates every patient record in the clinic —
> dental records, tooth conditions, prescriptions, treatment plans,
> invoices, payments. Two HTTP requests, no email proven, no rate
> limit, no trace.

The app deliberately has **no roles** — every authenticated user is an
equal front-desk staff member (CLAUDE.md, Hard constraints). That
design is fine on its own. What makes it dangerous is that
"authenticated" currently means "anyone on the internet who filled in
a form". This sub-project closes the gap between *who the app thinks
its users are* (clinic staff) and *who can actually become one*
(anybody), and fixes the surrounding authentication weaknesses that
make a staff account cheap to take over once it exists.

Sibling sub-projects, specced separately:

- **8.2 Data integrity & abuse resistance** — destructive actions,
  concurrency races, public-endpoint abuse.
- **8.3 Transport & deployment hardening** — response headers, CSP,
  session cookie flags, proxies, config defaults.

## Findings addressed

Each is a verified finding from the Phase 8 review. Severity is as
assessed against a deployment holding real patient data.

| # | Finding | Severity |
|---|---------|----------|
| A1 | `App\Models\User` does not implement `MustVerifyEmail`, so the `verified` middleware on `/dashboard` is a no-op and **no verification mail is ever sent** | High |
| A2 | `POST /register` is anonymous, unthrottled, and grants immediate full access to all patient data | Critical |
| A3 | `POST /forgot-password` confirms whether an email is registered, and carries no throttle | Medium |
| A4 | Login throttle is keyed `email\|ip` only — no per-IP cap, so password spraying across many accounts is unlimited | Medium |
| A5 | Password policy is `min(8)` and nothing else — `password` is a valid staff password | Medium |
| A6 | Changing a password neither rotates `remember_token` nor invalidates sibling sessions | Medium |
| A7 | Changing the account email — the recovery channel — requires only a session cookie | Low |
| A8 | `register`, `forgot-password`, `reset-password`, `confirm-password`, and `PUT /password` have no rate limiting of any kind | Medium |

### Verified evidence

- `app/Models/User.php:5` — `// use Illuminate\Contracts\Auth\MustVerifyEmail;`
  (commented out, stock Breeze default); `:11` — `class User extends
  Authenticatable`, no `implements`. `EnsureEmailIsVerified::handle()`
  gates on `$request->user() instanceof MustVerifyEmail`, so it passes
  through unconditionally. `SendEmailVerificationNotification` gates on
  the same contract, so `event(new Registered($user))` at
  `RegisteredUserController.php:46` sends nothing.
- `routes/web.php:53-55` — `verified` is applied to `/dashboard` only;
  the staff route group at `:57` carries `auth` alone.
- `PasswordResetLinkController.php:43-49` — a miss returns a field
  error, a hit returns a flash `status`. Structurally different
  responses.
- `LoginRequest.php:82-85` — throttle key is `Str::lower($email).'|'.$ip`.
- `AppServiceProvider.php` — never calls `Password::defaults(...)`, so
  `Rules\Password::defaults()` falls through to `min(8)`.

## Constraints

- **No roles, still.** This sub-project does not introduce RBAC,
  permissions, or a staff/admin split. Every authenticated user
  remains an equal front-desk staff member. The fix is about *who gets
  to authenticate*, not what they can do afterwards.
- **No new mail transport.** Email verification uses Laravel's built-in
  `VerifyEmail` notification over the existing `log` mailer in dev and
  `array` in tests. Nothing leaves the fictional clinic's fake domain.
  CLAUDE.md's enumerated list of senders must be updated to include it.
- **No external network calls.** Notably, `Rules\Password::uncompromised()`
  queries the HaveIBeenPwned API over the network on every password
  set. That contradicts the app's "nothing is transmitted anywhere"
  posture and would make the test suite network-dependent and flaky.
  It is **not** used; a composition rule is used instead, and
  `uncompromised()` is documented as a deployer opt-in.
- **The dev flow must keep working out of the box.** `composer setup`
  copies `.env.example` and a developer must still be able to register
  at `/register` without hunting for a value. Secure defaults must not
  mean a broken fresh clone.
- **No 2FA, no passkeys, no SSO.** Out of scope; noted as future work.

## Design

### 1. Registration is gated by a clinic registration code (A2)

`config/clinic.php` gains:

```php
// The shared code a new staff member must supply at /register.
// Empty or unset disables self-registration entirely.
'registration_code' => env('REGISTRATION_CODE'),
```

`config/clinic.php` is the right home: it already holds per-clinic
operational settings (`closed_days`, `max_requests_per_slot`,
`morning_starts_at`), not just identity, and this is exactly the kind
of value a real clinic swaps on deployment.

**Why a shared code rather than per-invite tokens.** In an app with no
roles, "any staff member can invite a new staff member" is functionally
identical to "staff know a shared code" — there is no privileged
inviter to distinguish. A code delivers the same security property
without a token model, an invitations table, an admin UI, and an email
flow that would all have to be designed around a flat-staff model they
do not fit. Per-invite tokens buy one thing a code does not — an audit
trail of who admitted whom — which is worth having only once roles
exist. Recorded in "Out of scope".

Behaviour:

- `RegisteredUserController::create()` (the `GET`) and `store()` both
  **abort 403 when the configured code is empty or unset**. Registration
  fails closed, so a deployer who blanks the value has switched
  self-registration off — a legitimate "onboarding finished" state.
- `store()` validates a new `registration_code` field: `required`,
  `string`, and compared to the configured value with `hash_equals()`
  so the check is not a character-by-character timing oracle.
- A wrong code returns a validation error on the `registration_code`
  field. It deliberately does **not** distinguish "wrong code" from
  "registration disabled" beyond the 403/422 split already implied.
- `.env.example` ships `REGISTRATION_CODE=harborview-dev` so a fresh
  clone works immediately, with a comment stating that a real
  deployment must replace it.

**First-user bootstrapping.** Because the app has no seeded login and
no admin-side "create user" screen, a deployment that starts with an
empty code has no way to create its first staff account. The ordering
is therefore part of the deployment procedure, not an afterthought: set
`REGISTRATION_CODE` to a strong value, register the founding staff
account, then blank it once onboarding is done if self-registration is
no longer wanted. Sub-project 8.3's README deployment checklist records
this sequence explicitly.

Frontend: `resources/js/Pages/Auth/Register.jsx` gains a
`registration_code` text input above the submit button, labelled
"Clinic registration code" with helper text "Ask the practice manager
for this."

### 2. Email verification becomes real (A1)

- `app/Models/User.php` — uncomment the import, `class User extends
  Authenticatable implements MustVerifyEmail`.
- `routes/web.php` — the staff group at `:57` becomes
  `Route::middleware(['auth', 'verified'])`. The now-redundant
  `verified` on the `/dashboard` route is collapsed into it, and
  `/dashboard` moves inside the group.
- `/profile`, logout, and the verification-notice/resend routes stay
  reachable to an authenticated-but-unverified user — they already sit
  under `auth` without `verified` in `routes/auth.php`, which is
  correct and needs no change.
- `ProfileController::edit()`'s `'mustVerifyEmail' => $request->user()
  instanceof MustVerifyEmail` starts returning `true` on its own, which
  activates the already-built resend banner in `Profile/Edit.jsx`. No
  change needed there.
- The `verify-email` routes, `VerifyEmailController`, and
  `Auth/VerifyEmail.jsx` — currently unreachable dead code — become
  live. No changes needed; they are stock Breeze and correct.

**Test-suite impact is small.** `database/factories/UserFactory.php:29`
already sets `'email_verified_at' => now()`, so every factory-created
user in the existing 334 tests is verified and keeps passing.
`DemoSeeder`'s throwaway staff user must be given a verified timestamp
so it stays consistent with its documented purpose.

### 3. `/forgot-password` stops confirming who exists (A3)

`PasswordResetLinkController::store()` returns the same response for
every outcome:

```php
$status = Password::sendResetLink($request->only('email'));

if ($status !== Password::RESET_LINK_SENT) {
    Log::info('Password reset link not sent.', ['status' => $status]);
}

return back()->with('status', __(Password::RESET_LINK_SENT));
```

The real broker status is logged server-side so a genuine mail failure
is still diagnosable. `Auth/ForgotPassword.jsx` needs no change — it
already renders whatever `status` it is given.

Note the existing `/my-appointments` lookup already takes exactly this
posture for patients; this brings staff accounts in line with it.

### 4. Login gains a per-IP cap (A4)

`LoginRequest::ensureIsNotRateLimited()` keeps the existing
5-per-`email|ip` bucket and adds a second, coarser one keyed on IP
alone at 20 attempts per minute. Both are checked before
`Auth::attempt`; both are hit on failure; only the email bucket is
cleared on success, so a shared-IP clinic does not reset an attacker's
budget by having one staff member log in.

The lockout message reuses the existing `auth.throttle` translation and
reports the longer of the two available-in times.

### 5. A real password policy (A5)

`AppServiceProvider::boot()`:

```php
Password::defaults(fn () => Password::min(12)->letters()->numbers());
```

All three call sites (`RegisteredUserController`, `NewPasswordController`,
`PasswordController`) already use `Password::defaults()` and pick this
up with no change.

`->uncompromised()` is deliberately omitted — see Constraints. A
comment on the `defaults()` call records that a deployer who accepts an
outbound HTTPS call to the HaveIBeenPwned range API can add it.

Existing tests that submit `'password'` as a new password on
register/reset/profile paths will start failing correctly and are
updated to a conforming value. Tests that merely *log in* with the
factory password are unaffected — the policy applies on write, not on
authentication.

### 6. Password changes end other sessions (A6)

Two changes:

- `PasswordController::update()` also rotates the remember token:
  `$user->forceFill(['remember_token' => Str::random(60)])`, matching
  what `NewPasswordController.php:49-52` already does on reset. This
  kills a stolen `remember_web_*` cookie, which otherwise survives for
  the `Cookie::forever` lifetime.
- `bootstrap/app.php` registers
  `\Illuminate\Session\Middleware\AuthenticateSession::class` in the
  `web` group, so a password-hash change logs out sibling sessions
  rather than leaving them live.

This closes the case where a staff member notices something wrong,
changes their password, and the attacker's session keeps working.

### 7. Changing the account email requires the current password (A7)

`ProfileUpdateRequest::rules()` gains:

```php
'current_password' => [
    Rule::requiredIf(fn () => $this->input('email') !== $this->user()->email),
    'current_password',
],
```

Conditional on the email actually *changing*, not merely being present
— the profile form always submits the field, so `required_with` would
demand a password on every name-only edit.

`Profile/Partials/UpdateProfileInformationForm.jsx` gains a
`current_password` input, shown when the email field has been edited,
labelled "Current password — required to change your email address".

This closes the takeover chain: hijacked session → change email →
request a password reset → own the account permanently, surviving the
victim's logout.

### 8. Every auth endpoint is throttled (A8)

`routes/auth.php` — add `throttle:6,1` to:

- `POST /register`
- `POST /forgot-password`
- `POST /reset-password`
- `POST /confirm-password`
- `PUT /password`

`POST /login` keeps its in-request limiter (which is finer-grained than
a route throttle, and now has the per-IP bucket from §4).
`verification.send` and `verification.verify` already carry
`throttle:6,1` and need no change.

## Testing

`tests/Feature/` is flat (CLAUDE.md), so this adds/extends:

- `tests/Feature/Auth/RegistrationTest.php` (existing) —
  - registration is rejected without a code
  - registration is rejected with a wrong code
  - registration succeeds with the correct code
  - `GET`/`POST /register` 403 when `clinic.registration_code` is empty
  - a registered user is unverified and receives a verification
    notification (`Notification::fake()`)
- `tests/Feature/Auth/EmailVerificationTest.php` (existing) — an
  unverified user hitting a staff route is redirected to
  `verification.notice`; a verified one is not.
- **A new `tests/Feature/StaffRouteProtectionTest.php`** that walks every
  named route in the staff group and asserts each one redirects a
  guest to login *and* redirects an authenticated-but-unverified user
  to the verification notice. This is the regression net for A1/A2 —
  it fails loudly if a future route is added outside the group.
- `tests/Feature/Auth/PasswordResetTest.php` (existing) — the response
  for an unknown email is byte-identical to the response for a known
  one; the route is throttled.
- `tests/Feature/Auth/AuthenticationTest.php` (existing) — the per-IP
  cap trips after 20 failed attempts spread across distinct emails.
- `tests/Feature/Auth/PasswordUpdateTest.php` (existing) — a password
  change rotates `remember_token`; a sibling session is logged out.
- `tests/Feature/ProfileTest.php` (existing) — changing the email
  without `current_password` fails; changing only the name does not
  require it.
- A password-policy test asserting `min(12)` + letters + numbers is
  enforced on register, reset, and profile password update.

Every finding gets a test that fails before the fix and passes after —
written first, per the project's TDD workflow.

## Out of scope (future slices)

- **Roles / RBAC.** Still deliberately absent. A staff-vs-admin split,
  per-patient access scoping, and an audit log of who read what are the
  natural Phase 4-or-later companions to a real clinic deployment.
- **Per-invite registration tokens with an audit trail** of who admitted
  whom — worth building once roles exist and there is a privileged
  inviter to record. The shared code is the roles-free equivalent.
- **Two-factor authentication, passkeys, SSO.**
- **Session lifetime, cookie flags, and `SESSION_ENCRYPT`** — these are
  configuration and belong to sub-project 8.3.
- **Account lockout with admin unlock** — the per-IP and per-account
  throttles are the proportionate control at this scale; a durable
  lockout needs an unlock path, which needs roles.
- **Re-authentication before viewing clinical records** (`password.confirm`
  exists in the codebase but is applied to zero routes). Worth
  considering when a real clinic defines its own policy.

## Known gaps (to record in `CLAUDE.md` on ship)

- The registration code is a single shared secret with no rotation
  mechanism, no per-user attribution, and no expiry. If it leaks, it is
  changed by editing the environment and restarting. Adequate for a
  flat-staff model; a real clinic wanting to know who admitted whom
  needs per-invite tokens.
- `Password::defaults()` omits `uncompromised()` to avoid an outbound
  network call, so a password that is long and mixed but publicly
  breached is still accepted.
- `AuthenticateSession` logs out sibling sessions on a password change
  but there is still no "sign out all devices" control, and no list of
  active sessions.
- Registration remains self-service. A clinic that wants staff accounts
  provisioned centrally has no admin-side "create user" screen.
