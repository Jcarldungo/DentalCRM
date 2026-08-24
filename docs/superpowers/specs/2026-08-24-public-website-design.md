# DentalCRM — Phase 2: Public Website — Design

Status: approved by user, 2026-08-24.

## Purpose

Turn `/` from an internal-dashboard redirect into a polished public-facing
marketing site for the clinic, while leaving the existing authenticated
staff application (`/dashboard`, `/patients`, `/providers`, `/appointments`)
untouched in behavior and layout. This is Phase 2 of the platform vision in
[`docs/PLATFORM_VISION.md`](../../PLATFORM_VISION.md) — see that doc for the
full future roadmap (patient portal, RBAC, clinical system, billing, etc.),
none of which is in scope here.

## Product distinction

Three separate experiences exist or will exist:

- **Public website** (this phase) — marketing, trust-building, services,
  dentist introductions, clinic info, contact/inquiry conversion. No auth.
- **Patient portal** — not this phase; a later phase, blocked on RBAC.
- **Internal staff application** — existing authenticated app. Must keep
  working exactly as it does today, with its own layout and nav, entirely
  separate from the public site's.

## Scope

In scope:
- Public pages: Home, Services, Dentists, About, Contact
- Contact/inquiry form (name, email, phone, message, optional service of
  interest) → stored in a new `inquiries` table, no email sending
- A new internal `/inquiries` page (authenticated) so staff can see
  submissions — otherwise the data would be write-only
- `PublicLayout.jsx`, distinct from `AuthenticatedLayout.jsx`
- `/` now serves the public homepage instead of redirecting to `/dashboard`

Explicitly deferred (per the vision doc, not gaps in this phase):
real appointment booking/availability, patient auth/portal, patient
records/dental chart/treatment plans/prescriptions, billing, inventory,
DB-backed services or dentist profiles, email/SMS notifications.

## Architecture

Same Laravel app, no new packages except `lucide-react` (icons — see
Visual System). New route group with no `auth` middleware:

```
routes/web.php
  GET  /            PublicSiteController@home        name: home
  GET  /services    PublicSiteController@services     name: services
  GET  /dentists    PublicSiteController@dentists      name: dentists
  GET  /about       PublicSiteController@about         name: about
  GET  /contact     PublicSiteController@contact       name: contact
  POST /contact     InquiryController@store            name: inquiries.store
                     (throttle:6,1 — basic abuse guard, no other rate limiting exists yet)

  auth group (existing, unchanged) adds:
  GET   /inquiries              Admin\InquiryController@index name: inquiries.index
  PATCH /inquiries/{inquiry}    Admin\InquiryController@update name: inquiries.update
```

`Route::redirect('/', '/dashboard')` is removed. A logged-in staff member
visiting `/` now sees the public homepage like anyone else — they reach the
internal app via `/login` → `/dashboard`, same as today.

```
app/Http/Controllers/PublicSiteController.php   home/services/dentists/about/contact
app/Http/Controllers/InquiryController.php      store (public)
app/Http/Controllers/Admin/InquiryController.php index (authenticated)
app/Models/Inquiry.php
database/migrations/xxxx_create_inquiries_table.php
database/factories/InquiryFactory.php

resources/js/Layouts/PublicLayout.jsx           header nav + footer + <main> slot
resources/js/Components/Public/                 Button, Container, SectionHeading,
                                                 ServiceCard, DentistCard, Avatar,
                                                 TestimonialCard, FaqItem
resources/js/Data/                              services.js, dentists.js,
                                                 testimonials.js, faqs.js  (static content)
resources/js/Pages/Public/                      Home, Services, Dentists, About, Contact
resources/js/Pages/Admin/Inquiries/Index.jsx    staff-facing list (reuses Badge)
```

`Data/*.js` holds the hardcoded content (services, dentist bios,
testimonials, FAQs) as plain arrays imported by the page components — no
database tables, no admin editor, per the earlier static-content decision.

## Data model — `Inquiry`

```
id
name                string, required
email               string, required
phone               string, nullable
service_interest    string, nullable   — free text, e.g. "Teeth Whitening"
message             text, required
read_at             timestamp, nullable — null = "New", set = "Read"
timestamps
```

`InquiryController@store` validates and creates; no mail is sent.
`Admin\InquiryController@index` returns all inquiries ordered
`latest('created_at')`. Marking as read is a small `PATCH /inquiries/{inquiry}`
(`read_at = now()`) fired when staff open an inquiry — kept in this phase
since an "Inquiries" list where nothing can ever be marked read isn't
usable, but no other inquiry management (reply, delete, notes) is added.

## Visual system

Palette (Tailwind tokens, extending the existing config):
- Primary accent: `teal-700` (links, primary buttons, icons), hover `teal-800`
- Neutral surfaces: alternating `white` / `stone-50` section backgrounds
- Text: `stone-900` headings, `stone-600` body copy
- Borders: `stone-200`
- Status accents (reused, not new): the existing `Badge` component's `warn`
  (amber) tone for "New" inquiries, `muted` (gray) for "Read" — no new Badge
  tones needed

Typography: keep the existing Figtree font (already loaded for the internal
app) rather than adding a second font — a confident type scale (larger,
`tracking-tight` headings; generous `leading-relaxed` body copy; restrained
weight variation) does the work of feeling premium, not a second typeface.

Imagery: no real clinic photos or dentist headshots exist for this
portfolio project. Rather than fabricate photorealistic images of "real"
dentists, dentist profiles use a consistent **initials avatar** treatment
(colored circle, initials, via a small `Avatar` component) instead of
photos. The hero uses an abstract SVG composition (soft geometric shapes /
a simple smile-line motif) rather than stock photography. This is a
deliberate placeholder — real photos can replace the avatars later without
changing the component contract.

Icons: add `lucide-react` (small, tree-shakable) for service icons, the
"why choose us" section, nav, and FAQ chevrons — meaningfully improves
scannability per the spec, not decoration for its own sake.

## Page content

**Home** — Hero (value prop + "Contact Us" CTA, explicitly not phrased as
booking) → Services preview (4–6 of the 12, "View All Services" CTA) → Why
Choose Us (trust themes, not statistics) → Featured Dentists (3, "Meet Our
Dentists" CTA) → Testimonials (3, clearly fictional sample content
consistent with the rest of this portfolio project's demo data) → FAQ
(accessible accordion, 5 questions) → Location/Hours (scannable block, no
live map — just address/phone/email/hours) → final Contact CTA.

**Services** — all 12 services from the vision doc as a card grid: name,
description, est. duration, starting price where sensible, each linking to
`/contact`.

**Dentists** — team grid: avatar, name, specialty, bio, credentials,
experience. Explicitly not linked to the internal `providers` table.

**About** — clinic story, mission, values, facilities, patient experience —
short sections with supporting visuals (icons/pull-quotes), not a wall of
text.

**Contact** — clinic info block + the inquiry form. On submit: button shows
a `processing` state (Inertia `useForm`) and disables to prevent double
submits; on success the form is replaced with a confirmation panel ("Thanks
— our clinic team will get back to you shortly.") rather than resetting in
place, which also structurally prevents accidental resubmission.

## Navigation

Header: logo · Home / Services / Dentists / About / Contact · prominent
"Contact" button. Mobile: hamburger toggling a slide-down panel (not a
shrunk desktop nav), `aria-expanded` wired up, closes on link click.

Footer: clinic info, nav links, services list, contact info, hours, social
links (if any), copyright.

## Accessibility

Semantic landmarks (`header`/`nav`/`main`/`footer`), correct heading order
(one `h1` per page), FAQ built on native `<details>/<summary>` (keyboard-
and screen-reader-accessible for free) rather than a custom-scripted
accordion, visible focus rings (Tailwind's default focus-visible ring, not
suppressed), labeled form fields with associated `<label>`, validation
messages tied to fields via `aria-describedby`, sufficient color contrast
(the teal/stone palette is chosen to meet WCAG AA at text sizes used),
descriptive `alt` text on the SVG hero graphic (or `aria-hidden` if purely
decorative), and no state communicated by color alone (the New/Read badge
also carries text, not just color).

## Testing

- `tests/Feature/InquiryTest.php` — validation (name/email/message
  required, phone/service_interest optional), successful storage, guest can
  submit (no auth required), basic throttling doesn't block a single
  legitimate submission.
- `tests/Feature/Admin/InquiryTest.php` — guest redirected to `/login` for
  `/inquiries`, authenticated user sees the list, newest-first ordering,
  marking one as read sets `read_at`.
- `tests/Feature/PublicPagesTest.php` — smoke test: `/`, `/services`,
  `/dentists`, `/about`, `/contact` all return 200 for a guest (content is
  static markup, so this checks routing/rendering wire-up rather than
  copy).

## Out of scope / explicitly not addressed here

Everything under "Important Scope Boundary" from the approved direction:
real booking/availability, patient auth/portal, patient records, dental
chart, treatment plans, prescriptions, billing, inventory, DB-backed
services or dentist profiles, email/SMS notifications. All remain phases
of `docs/PLATFORM_VISION.md`, not gaps in this one.
