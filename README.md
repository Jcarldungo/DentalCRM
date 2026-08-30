# DentalCRM

A dental clinic platform: a public marketing site with online appointment
requests, and an internal front-desk CRM behind it — patients, clinical
records, an odontogram, treatment plans, prescriptions, a live queue
board, invoicing, inventory, and reporting.

Built as a configurable product for real dental clinics. It currently
develops against a fictional stand-in clinic (Harborview Dental Clinic, a
made-up Makati address and a `.example` email domain), so clinic identity
is data-driven and swappable rather than hardcoded.

Laravel 12 · Inertia 2 · React 18 · Tailwind 3 · MySQL/MariaDB.

Design specs and implementation plans live in
[`docs/superpowers/`](docs/superpowers/); the long-range roadmap is
[`docs/PLATFORM_VISION.md`](docs/PLATFORM_VISION.md).

## Running it locally

Requires PHP 8.2+, Composer, Node 18+, and MySQL/MariaDB. Development on
Windows uses [Laravel Herd](https://herd.laravel.com/) (PHP 8.4).

```bash
composer install
npm install
cp .env.example .env          # the LOCAL template — see Deploying below
php artisan key:generate

# create both databases first (dentalcrm_dev, dentalcrm_testing), then:
php artisan migrate
php artisan db:seed --class=DemoSeeder   # sample clinic data

composer run dev   # app server + Vite + queue worker + scheduler
```

`composer run dev` is the one to use: `AppointmentLookupLink` is queued and
the day-before reminder runs on the scheduler, so neither works with
`php artisan serve` alone.

There is no seeded login — every signed-in user is an equal front-desk
staff member, and there are no roles. Register at `/register` with the
shared code in `REGISTRATION_CODE`, then verify the account's email (in
development the message goes to `storage/logs/laravel.log` via the `log`
mailer).

## Tests

```bash
php artisan test
```

Tests run against MariaDB (`dentalcrm_testing`), not SQLite, so that
database must exist. A fresh checkout also needs `npm run build` before
the suite passes — around 32 tests render real pages.

## Deploying

`.env.example` is the **local development** template: `APP_DEBUG=true` and
mail to the `log` driver are deliberate there. Copy
[`.env.production.example`](.env.production.example) instead, which carries
the secure values and explains each one.

Then, in order:

1. **`php artisan key:generate`** — never reuse a key across environments.
2. **Set `TRUSTED_PROXIES`** if the app sits behind a reverse proxy or load
   balancer. Without it every rate limiter collapses into a single global
   bucket — six requests from anyone locks out the whole clinic — and
   signed appointment-lookup links are generated as `http://`, which then
   fail validation when opened over `https://`.
3. **Set `APP_URL` to the real HTTPS origin.** Host-header validation and
   the CORS origin are both derived from it.
4. **Configure real SMTP.** With `MAIL_MAILER=log`, every appointment
   lookup email — each containing a live 30-minute signed URL — is written
   verbatim to a log file.
5. **Bootstrap the first account.** The app has no seeded login and no
   admin-side "create user" screen, so:
   - set `REGISTRATION_CODE` to a strong value,
   - register the founding staff account at `/register`,
   - verify its email,
   - blank the code again if self-registration is no longer wanted (blank
     disables `/register` entirely, GET and POST).

   Skipping this leaves a running application nobody can log into.
6. **`php artisan migrate --force`**, then `config:cache`, `route:cache`,
   `view:cache`, and `npm run build`.
7. **Run a queue worker** (`php artisan queue:work`) — the appointment
   lookup link never goes out without one.
8. **Add the scheduler cron entry** so day-before reminders fire:

   ```
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```

9. **Check it over with `php artisan about`** — confirm debug is off, the
   environment is `production`, and config is cached.

Response headers (CSP with a per-request nonce, HSTS on secure requests,
`frame-ancestors 'none'`, and the rest) are applied by
`App\Http\Middleware\SecurityHeaders` and need no configuration.

## Status

Shipped: the internal CRM, the public site, public appointment requests
with a passwordless status lookup, the queue board, dental records, the
odontogram, treatment plans, prescriptions, the dentist workspace,
invoicing and payments, reports, and inventory.

`CLAUDE.md` tracks what is deliberately deferred and the known gaps.
