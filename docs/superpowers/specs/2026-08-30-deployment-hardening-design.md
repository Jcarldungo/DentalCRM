# DentalCRM — Phase 8 sub-project 3: Transport & deployment hardening — Design

Status: draft, 2026-08-30.

## Purpose

The third of three Phase 8 security sub-projects. 8.1 fixed who can
authenticate; 8.2 fixed what the application does with legitimate
requests. This one covers everything between the browser and the app —
response headers, transport, proxy handling, and the configuration
defaults a deployer inherits.

Most of these findings are **deployment-readiness** rather than live
exploits: the app is not deployed, the clinic is fictional, and there is
no production environment to attack today. They matter because DentalCRM
is built as a sellable product. The moment a real clinic runs it, the
defaults in `.env.example` — which `composer setup` copies verbatim —
become that clinic's production configuration.

Two findings are live now regardless of deployment: the complete staff
route map is served to anonymous visitors on every public marketing
page, and no response carries a single security header.

Sibling sub-projects: **8.1 Access control & authentication** (specced),
**8.2 Data integrity & abuse resistance** (specced).

## Findings addressed

| # | Finding | Severity |
|---|---------|----------|
| C1 | No security-header middleware exists anywhere — no CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, HSTS, or `Permissions-Policy` | High (deployment) + live clickjacking |
| C2 | `.env.example` ships `APP_DEBUG=true`, and `composer setup` copies it — a production 500 would render env vars including `DB_PASSWORD` and `APP_KEY` | High (deployment) |
| C3 | `SESSION_SECURE_COOKIE` is absent so the cookie has no `Secure` flag; `SESSION_ENCRYPT=false` stores session payloads in plaintext; 120-minute idle lifetime survives browser close | High (deployment) |
| C4 | No trusted proxies configured — behind a load balancer every `throttle` limiter collapses into one global bucket and signed URLs are generated with `http://` | High (deployment) |
| C5 | Ziggy serves the complete staff route map — including both `DELETE` endpoints — to anonymous visitors on every public page | Medium (live) |
| C6 | No `TrustHosts` — a `Host` header injection puts an attacker's domain into a real password-reset link | Medium (deployment) |
| C7 | No HTTPS enforcement of any kind — no `forceScheme`, no HSTS, no redirect | Medium (deployment) |
| C8 | `HandleInertiaRequests` serializes the whole `User` model into every response, so any future column is exposed automatically | Low |
| C9 | `.gitignore` misses `.env.local`, `.env.staging`, `.env.testing`, `.env.*.local` | Low |
| C10 | `LOG_LEVEL=debug` + `MAIL_MAILER=log` + unrotated `single` channel writes patient names, appointment details, and **live 30-minute signed lookup URLs** to a plaintext file that grows forever | Low |
| C11 | No `config/cors.php`, so the framework default `allowed_origins => ['*']` applies (reaches only the dead `sanctum/csrf-cookie` route) | Informational |
| C12 | `laravel/framework` and `inertiajs/inertia-laravel` are each a major version behind | Informational |

Dependency scanning came back clean: `composer audit` reports no
advisories and `npm audit` reports 0 vulnerabilities in both the full
and production-only trees. No `.env` or secret has ever been committed
(`git log --all -- .env` and `git log --all -S "base64:"` are both
empty).

## Constraints

- **Local development must not get worse.** `.env.example` is the
  local-dev template that `composer setup` copies; turning debug off
  there to protect a hypothetical deployer would degrade every
  developer's daily experience. The fix is a separate deployment
  template, not a hostile default.
- **No external services.** Fonts already come from `fonts.bunny.net`
  and that stays; nothing new is added. No error-tracking SaaS, no
  CAPTCHA, no CDN.
- **The CSP must not break the app.** Recharts, FullCalendar, and
  Inertia's runtime style injection make a strict `style-src`
  impossible without removing charting — the policy is designed around
  what the app actually renders, not around an ideal.
- **No framework upgrade.** Laravel and Inertia majors are noted and
  deferred; an upgrade is its own project with its own spec.

## Design

### 1. A `SecurityHeaders` middleware (C1)

A single `app/Http/Middleware/SecurityHeaders.php`, appended to the
`web` group in `bootstrap/app.php`. It generates a per-request nonce and
sets:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — only when `$request->secure()` |
| `Content-Security-Policy` | see below |

```
default-src 'self';
script-src 'self' 'nonce-{n}';
style-src 'self' 'unsafe-inline' https://fonts.bunny.net;
font-src 'self' https://fonts.bunny.net;
img-src 'self' data:;
connect-src 'self';
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'none'
```

**Why `script-src` by nonce is cheap.** The only inline script is
Ziggy's `@routes` blob (`resources/views/app.blade.php:16`), and Ziggy
accepts a nonce: `@routes(nonce: Vite::cspNonce())`. Laravel's Vite
helper stamps the same nonce on the module tags it emits once
`Vite::useCspNonce($nonce)` is called, which the middleware does. In
dev, `@viteReactRefresh`'s inline module picks it up too.

**Why `style-src` needs `'unsafe-inline'`.** Inertia injects `<style>`
elements at runtime for the progress bar (enabled at
`resources/js/app.jsx:25`) and sets no nonce on them, and Recharts and
FullCalendar both write inline `style=` attributes — which nonces
cannot cover at all, only `'unsafe-inline'` or per-value hashes. Keeping
charting means keeping `'unsafe-inline'` for styles. It is recorded as a
known gap rather than pretended away.

`Referrer-Policy` matters concretely here: the signed lookup URL
`/my-appointments/{patient}?signature=…` is a bearer credential sitting
in the address bar of a page that loads a cross-origin stylesheet.
`strict-origin-when-cross-origin` sends only the origin, never the
signed path.

Dev-only additions (`app()->environment('local')`): `script-src` and
`connect-src` gain `http://localhost:5173`, and `connect-src` gains
`ws://localhost:5173` for HMR.

### 2. A real deployment template (C2, C3, C10)

`.env.example` keeps `APP_ENV=local` and `APP_DEBUG=true` — that is its
job — and gains a header comment saying so explicitly, pointing at the
new file.

A new **`.env.production.example`** carries the secure values:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://clinic.example

LOG_CHANNEL=daily
LOG_LEVEL=error

SESSION_DRIVER=database
SESSION_LIFETIME=30
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict

TRUSTED_PROXIES=
REGISTRATION_CODE=

MAIL_MAILER=smtp
```

The rationale per value:

- `SESSION_ENCRYPT=true` — the driver is `database`, so session payloads
  (including flash old-input from patient and prescription forms, i.e.
  patient names and emails) otherwise sit in the `sessions` table in
  base64 plaintext, readable from a backup.
- `SESSION_LIFETIME=30` + `expire_on_close` — a front-desk terminal is
  shared and walked away from; a 120-minute idle session that survives
  closing the browser is the wrong default for one holding patient
  records.
- `SESSION_SAME_SITE=strict` — the staff app has no cross-site entry
  point, so `lax` buys nothing.
- `LOG_LEVEL=error` + `LOG_CHANNEL=daily` — with `MAIL_MAILER=log`,
  every `AppointmentLookupLink` body is written to an unrotated file
  verbatim, including a live 30-minute signed URL. Moving mail off `log`
  is the real fix; rotation and level are defence in depth.
- `REGISTRATION_CODE=` — empty disables self-registration, per 8.1.
  It ships blank deliberately so a deployer cannot inherit a code that
  is public in this repository.

A **README deployment checklist** section makes this explicit and also
covers `php artisan key:generate`, `config:cache`, running
`schedule:run` from cron (already documented in CLAUDE.md), and a queue
worker for `AppointmentLookupLink`.

The checklist must spell out the **first-user bootstrapping order**,
because the app has no seeded login and no admin-side "create user"
screen: set `REGISTRATION_CODE` to a strong value → register the
founding staff account → verify its email → blank the code again if
self-registration is no longer wanted. A deployer who copies
`.env.production.example` verbatim and skips this has a running app
nobody can log into.

### 3. Trusted proxies (C4)

`bootstrap/app.php` gains `->trustProxies(at: ...)`, reading a
comma-separated `TRUSTED_PROXIES` and passing `null` when unset — so
behaviour is unchanged for anyone not behind a proxy, and correct for
anyone who is.

This is not cosmetic. With no trusted proxies behind a load balancer,
`$request->ip()` returns the balancer's address for every request, so
all four `throttle:6,1` limiters and `LoginRequest::throttleKey()`'s IP
half collapse into a single shared bucket — six requests from anyone
locks every legitimate visitor out of `/contact`, `/book`, and
`/my-appointments`. `$request->isSecure()` also returns false behind TLS
termination, which makes `URL::signedRoute()` emit `http://` links that
then fail signature validation when opened over `https://`.

### 4. Trusted hosts (C6)

`->trustHosts(at: [parse_url(config('app.url'), PHP_URL_HOST)])`, gated
to non-`local`, non-`testing` environments so the existing 334 tests and
local Herd hosts are unaffected.

Without it the `Host` header is never validated and Laravel derives
absolute URLs from it, so on a server with a catch-all vhost an attacker
can `POST /forgot-password` with `Host: evil.test` and cause a **real**
reset token for a **real** staff account to be mailed to that account
pointing at the attacker's domain. The same shape applies to the
appointment-lookup mail.

### 5. HTTPS enforcement (C7)

`AppServiceProvider::boot()`:

```php
if ($this->app->isProduction()) {
    URL::forceScheme('https');
}
```

Paired with HSTS from §1 and `SESSION_SECURE_COOKIE` from §2.

### 6. Ziggy stops publishing the staff route map (C5)

A new `config/ziggy.php` defines a `public` group listing only the
routes the public pages actually call — verified as `home`, `services`,
`dentists`, `about`, `contact`, `book`, `bookings.store`,
`inquiries.store`, and the three `appointments.lookup.*` routes (17
`route()` call sites across `resources/js/Pages/Public/*` and
`PublicLayout.jsx`).

`resources/views/app.blade.php` then emits the narrow group for guests:

```blade
@routes(auth()->check() ? null : 'public', Vite::cspNonce())
```

This is reconnaissance hardening, not an authorization fix — every one
of those routes is correctly behind `auth`. But an anonymous `curl` of
the homepage currently returns all ~55 named routes with URIs, methods,
and parameter names, including `patients.destroy` and
`providers.destroy`, which is exactly the map an attacker wants before
attempting CSRF or XSS against a staff session, and it enumerates the
product's entire internal feature set to any scraper.

### 7. Share an explicit user projection (C8)

`HandleInertiaRequests::share()` becomes
`$request->user()?->only('id', 'name', 'email')`.

Guests are already safe (`$request->user()` is null, and the review
confirmed no config values are shared). The problem is that the current
shape is "whatever columns `users` has" — the day someone adds
`two_factor_secret`, `api_token`, or a `notes` column, it is serialized
into the `data-page` attribute of every rendered page with no code
change to review. The frontend's actual uses of `auth.user` are audited
first to confirm the three-field projection is sufficient.

### 8. Small hygiene fixes (C9, C11)

- `.gitignore` gains `.env.*` with `!.env.example` and
  `!.env.production.example` negations, so a deployer creating
  `.env.staging` with live credentials cannot commit it silently.
- A minimal `config/cors.php` pins `allowed_origins` to the app URL
  instead of inheriting the framework's `['*']`. Not exploitable today
  (the only CORS-enabled route is the dead `sanctum/csrf-cookie`, and
  `supports_credentials` is false), but it stops being true the moment
  an API route is added.

## Testing

Header and config behaviour is testable through the HTTP kernel:

- A new `tests/Feature/SecurityHeadersTest.php` — every header is
  present on a public page and on a staff page; HSTS appears only on a
  secure request; the CSP contains a `nonce-` that matches the one
  rendered into the `@routes` script tag.
- `tests/Feature/PublicPagesTest.php` — a guest response does **not**
  contain `patients.destroy` / `providers.destroy` / `reports.index`;
  an authenticated response does.
- A test asserting the shared Inertia `auth.user` prop has exactly the
  three expected keys — this is the regression net for C8, and it fails
  if someone adds a column and forgets the projection.
- Existing tests are the regression net for the middleware additions:
  if `trustHosts` or the CSP breaks anything, the 334 existing tests
  fail. `npm run build` must pass, and the app must be loaded in a
  browser to confirm the CSP does not break Recharts, FullCalendar, or
  the Inertia progress bar — a passing test suite does not prove a CSP
  works, since tests never execute the page's JavaScript.

That last point is a real verification requirement, not a formality:
this is the one sub-project whose central change cannot be fully
verified by the test suite.

## Out of scope (future slices)

- **Upgrading Laravel and Inertia majors** (C12). No known-vulnerable
  versions; `laravel/framework ^12.0` is still supported. An upgrade is
  its own spec.
- **Self-hosting the webfont** to drop the two `fonts.bunny.net` CSP
  allowances and remove a third-party origin entirely. Genuinely
  tempting, but it changes public-site typography loading and belongs
  with a performance pass (also Phase 8) rather than here.
- **Removing `laravel/sanctum`**, which is required but unused — nothing
  in the app is stateful-API or token-authenticated. Dependency removal
  needs its own verification that Breeze does not lean on it.
- **A strict `style-src` without `'unsafe-inline'`**, which means
  replacing Recharts and FullCalendar or hashing every inline style
  attribute they emit.
- **CSP reporting** (`report-uri` / `report-to`) — needs an endpoint or
  a third-party collector.
- **Subresource Integrity** on the external stylesheet.
- **Rotating `APP_KEY`** / a secrets-management story for deployment.

## Known gaps (to record in `CLAUDE.md` on ship)

- The CSP carries `style-src 'unsafe-inline'` because Inertia injects
  runtime `<style>` elements without a nonce and Recharts/FullCalendar
  emit inline `style=` attributes, which nonces cannot cover. Script
  injection is blocked; style injection is not.
- HSTS is emitted only on already-secure requests, so it cannot protect
  the very first plaintext visit. That is inherent to HSTS without
  preloading.
- `.env.production.example` is documentation, not enforcement — nothing
  verifies that a deployment actually used it. `php artisan about` is
  the manual check, listed in the README checklist.
- Trusted proxies default to none, which is the safe default but means
  a deployer behind a load balancer *must* set `TRUSTED_PROXIES` or all
  rate limiting silently becomes global.
- Ziggy's `public` group must be extended by hand whenever a public page
  starts calling a new route — the same manual-sync situation as the
  app's other documented duplicated const sets. A missing entry
  surfaces as a `route()` error on the public site.
