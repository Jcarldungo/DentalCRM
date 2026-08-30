# DentalCRM

An internal front-desk CRM for a single dental clinic — patient records,
appointment scheduling, and recall reminders.

Standalone Laravel + Inertia + React app, built as a personal/portfolio
project (no employer or client data involved).

See [`docs/superpowers/specs/2026-08-23-dental-crm-design.md`](docs/superpowers/specs/2026-08-23-dental-crm-design.md)
for the design.

## Running it locally

Requires PHP 8.2+, Composer, Node 18+, and MySQL/MariaDB. This machine uses
XAMPP, so PHP and MariaDB come from `C:\xampp`.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# create the databases first (dentalcrm_dev, dentalcrm_testing), then:
php artisan migrate
php artisan db:seed --class=DemoSeeder   # optional sample patients/appointments

npm run dev        # in one terminal
php artisan serve  # in another
```

Register an account at `/register` — there's no seeded login, since every
signed-in user is an equal front-desk staff member (no roles in v1).
Registration requires a shared clinic registration code
(`REGISTRATION_CODE` in `.env`), and the new account must verify its
email (sent via the `log` mailer in dev) before reaching any staff page.

## Tests

```bash
php artisan test
```

Tests run against MariaDB (`dentalcrm_testing`), not SQLite.

## Status

v1 complete: patient records, appointment scheduling (FullCalendar), and
recall reminders. See `docs/superpowers/specs/2026-08-23-dental-crm-design.md`
for what's deliberately deferred (multi-tenancy, billing, notifications,
treatment pipeline, patient-facing features).
