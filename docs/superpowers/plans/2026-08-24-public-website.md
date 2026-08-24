# DentalCRM Phase 2: Public Website Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `/`'s redirect-to-dashboard with a polished public marketing site (Home, Services, Dentists, About, Contact) plus a contact-inquiry pipeline, without touching the existing internal staff application's behavior or layout.

**Architecture:** Same Laravel app, new unauthenticated route group serving Inertia pages under `resources/js/Pages/Public/` via a new `PublicLayout.jsx`, distinct from `AuthenticatedLayout.jsx`. Static marketing content (services/dentists/testimonials/FAQs) lives in plain JS data modules — no new database tables for content. One real new table, `inquiries`, backs the contact form and a new authenticated `/inquiries` staff page.

**Tech Stack:** Laravel 12, Inertia 2 + React 18 (existing), Tailwind CSS 3 (existing), `lucide-react` (new — icons), PHPUnit via `php artisan test` (existing).

**Spec:** `docs/superpowers/specs/2026-08-24-public-website-design.md`

## Global Constraints

- No new backend packages. The only new dependency anywhere is the frontend package `lucide-react`.
- Services and dentist profiles are static JS data (`resources/js/Data/*.js`) — no DB tables, no admin CRUD for them.
- No email or SMS sending anywhere in this phase.
- `AuthenticatedLayout.jsx` gets exactly one addition (an "Inquiries" nav link, both desktop and mobile blocks) — no other changes to the internal app's layout or existing pages.
- `Route::redirect('/', '/dashboard')` is removed; `/` must serve the public homepage. The internal app is reached via `/login` → `/dashboard`, unchanged.
- Tests follow the existing flat convention: `tests/Feature/<Name>Test.php`, run via `php artisan test`, against the MariaDB `dentalcrm_testing` database already configured in `phpunit.xml`.
- Clean-codebase rules apply throughout: no `dd()`/`console.log`/`var_dump`, no unused imports, no commented-out code.
- Prices use the Philippine peso sign `₱`, matching `docs/PLATFORM_VISION.md`'s own example figures.
- The clinic's identity is fictional: name "Harborview Dental Clinic", fictional address/phone, and a `.example` email domain (never a real, resolvable domain) — consistent with this being a portfolio project with no real clinic behind it. Dentist profiles use initials-avatars (a small `Avatar` component), not fabricated photos of real-looking people. Testimonials are clearly fictional sample content (first name + last initial only), matching the rest of this project's demo data conventions.

---

### Task 1: Inquiry backend — model, migration, public submission, staff-facing listing

**Files:**
- Create: `database/migrations/xxxx_create_inquiries_table.php`
- Create: `app/Models/Inquiry.php`
- Create: `database/factories/InquiryFactory.php`
- Create: `app/Http/Controllers/InquiryController.php`
- Create: `app/Http/Controllers/Admin/InquiryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InquiryTest.php`
- Test: `tests/Feature/InquiryManagementTest.php`

**Interfaces:**
- Produces: `Inquiry` model (`name`, `email`, `phone`, `service_interest`, `message`, `read_at`). Route names `inquiries.store` (public POST `/contact`), `inquiries.index` (auth GET `/inquiries`), `inquiries.update` (auth PATCH `/inquiries/{inquiry}`, body `{read: bool}`). Task 8 (Contact page) posts to `inquiries.store`. Task 9 (Admin Inquiries page) consumes `inquiries.index`/`inquiries.update`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/InquiryTest.php

namespace Tests\Feature;

use App\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_an_inquiry(): void
    {
        $response = $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
            'service_interest' => 'Teeth Whitening',
            'message' => 'I would like to book a whitening consultation.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inquiries', [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'service_interest' => 'Teeth Whitening',
        ]);
    }

    public function test_name_email_and_message_are_required(): void
    {
        $response = $this->post(route('inquiries.store'), []);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'not-an-email',
            'message' => 'Hello',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_new_inquiry_has_no_read_at_timestamp(): void
    {
        $this->post(route('inquiries.store'), [
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'message' => 'Hello',
        ]);

        $this->assertNull(Inquiry::first()->read_at);
    }
}
```

```php
<?php
// tests/Feature/InquiryManagementTest.php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_inquiries(): void
    {
        $response = $this->get(route('inquiries.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_inquiries(): void
    {
        $this->actingUser();
        Inquiry::factory()->create(['name' => 'Angela Reyes']);

        $response = $this->get(route('inquiries.index'));

        $response->assertOk();
    }

    public function test_inquiries_are_ordered_newest_first(): void
    {
        $this->actingUser();
        $older = Inquiry::factory()->create(['created_at' => now()->subDay()]);
        $newer = Inquiry::factory()->create(['created_at' => now()]);

        $response = $this->get(route('inquiries.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('inquiries.0.id', $newer->id)
            ->where('inquiries.1.id', $older->id)
        );
    }

    public function test_inquiry_can_be_marked_as_read(): void
    {
        $this->actingUser();
        $inquiry = Inquiry::factory()->create(['read_at' => null]);

        $response = $this->patch(route('inquiries.update', $inquiry), ['read' => true]);

        $response->assertRedirect();
        $this->assertNotNull($inquiry->fresh()->read_at);
    }

    public function test_guest_cannot_mark_an_inquiry_as_read(): void
    {
        $inquiry = Inquiry::factory()->create();

        $response = $this->patch(route('inquiries.update', $inquiry), ['read' => true]);

        $response->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run both to confirm they fail**

```bash
php artisan test tests/Feature/InquiryTest.php tests/Feature/InquiryManagementTest.php
```

Expected: FAIL — `route('inquiries.store')` (and related route names) undefined, `Inquiry` class not found.

- [ ] **Step 3: Create the migration, model, and factory**

```bash
php artisan make:model Inquiry -mf
```

```php
<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_inquiries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('service_interest')->nullable();
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
```

```php
<?php
// app/Models/Inquiry.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'phone', 'service_interest', 'message', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
```

```php
<?php
// database/factories/InquiryFactory.php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('09#########'),
            'service_interest' => $this->faker->randomElement(['Dental Cleaning', 'Braces', 'Teeth Whitening', null]),
            'message' => $this->faker->sentence(12),
            'read_at' => null,
        ];
    }
}
```

```bash
php artisan migrate
```

- [ ] **Step 4: Create the controllers**

```php
<?php
// app/Http/Controllers/InquiryController.php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'service_interest' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        Inquiry::create($validated);

        return back();
    }
}
```

```php
<?php
// app/Http/Controllers/Admin/InquiryController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InquiryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Inquiries/Index', [
            'inquiries' => Inquiry::latest()->get(),
        ]);
    }

    public function update(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $request->validate(['read' => ['required', 'boolean']]);

        $inquiry->update(['read_at' => $request->boolean('read') ? now() : null]);

        return back();
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add near the top (before the `dashboard` route) — this is a public route, outside the `auth` group:

```php
use App\Http\Controllers\InquiryController;

Route::post('/contact', [InquiryController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('inquiries.store');
```

Inside the existing `Route::middleware('auth')->group(...)` block, alongside the `appointments` routes:

```php
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;

Route::get('/inquiries', [AdminInquiryController::class, 'index'])->name('inquiries.index');
Route::patch('/inquiries/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
```

- [ ] **Step 6: Run the tests — confirm PASS**

```bash
php artisan test tests/Feature/InquiryTest.php tests/Feature/InquiryManagementTest.php
```

Expected: all 9 pass.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add Inquiry model, public contact submission, and staff inquiry listing

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Static marketing content (services, dentists, testimonials, FAQs)

**Files:**
- Create: `resources/js/Data/services.js`
- Create: `resources/js/Data/dentists.js`
- Create: `resources/js/Data/testimonials.js`
- Create: `resources/js/Data/faqs.js`

**Interfaces:**
- Produces: `services` (array of `{slug, name, description, duration, price, icon}`, 12 entries — `icon` is a `lucide-react` component reference), `dentists` (array of `{slug, name, specialty, bio, credentials: string[], experience}`, 3 entries), `testimonials` (array of `{name, service, quote}`, 3 entries), `faqs` (array of `{question, answer}`, 5 entries). Consumed by Task 3's `ServiceCard`/`DentistCard`/`TestimonialCard`/`FaqItem` and by Tasks 4–7's pages.
- Consumes: `lucide-react` (installed in Task 3 — this task's file imports it, but isn't run/built until Task 3 exists; that's fine, no test executes this file in isolation).

This task is pure static data with no independent behavior to test — verification is that Task 3 onward can import and render it without error.

- [ ] **Step 1: Create the services data**

```js
// resources/js/Data/services.js

import {
    Sparkles,
    Wrench,
    Syringe,
    Activity,
    Award,
    Layers,
    Grid3x3,
    Sun,
    Shield,
    Smile,
    Baby,
    Stethoscope,
} from 'lucide-react';

export const services = [
    {
        slug: 'dental-cleaning',
        name: 'Dental Cleaning',
        description: 'Professional plaque and tartar removal to keep your teeth and gums healthy.',
        duration: '45 minutes',
        price: 'From ₱1,500',
        icon: Sparkles,
    },
    {
        slug: 'dental-fillings',
        name: 'Dental Fillings',
        description: 'Durable, tooth-colored fillings that restore cavities without compromising your smile.',
        duration: '30–60 minutes',
        price: 'From ₱1,800',
        icon: Wrench,
    },
    {
        slug: 'tooth-extraction',
        name: 'Tooth Extraction',
        description: 'Safe, comfortable removal of damaged or problematic teeth.',
        duration: '30 minutes',
        price: 'From ₱2,000',
        icon: Syringe,
    },
    {
        slug: 'root-canal-treatment',
        name: 'Root Canal Treatment',
        description: 'Relieve pain and save an infected tooth with gentle, modern endodontic care.',
        duration: '60–90 minutes',
        price: 'From ₱8,000',
        icon: Activity,
    },
    {
        slug: 'dental-crowns',
        name: 'Dental Crowns',
        description: 'Custom-fitted crowns that protect and strengthen weakened teeth.',
        duration: '2 visits',
        price: 'From ₱12,000',
        icon: Award,
    },
    {
        slug: 'dental-implants',
        name: 'Dental Implants',
        description: 'A permanent, natural-looking replacement for missing teeth.',
        duration: 'Multiple visits',
        price: 'From ₱45,000',
        icon: Layers,
    },
    {
        slug: 'braces',
        name: 'Braces',
        description: 'Traditional orthodontic treatment to gradually straighten and align teeth.',
        duration: 'Ongoing treatment',
        price: 'From ₱60,000',
        icon: Grid3x3,
    },
    {
        slug: 'teeth-whitening',
        name: 'Teeth Whitening',
        description: 'Brighten your smile safely with professional-grade whitening treatment.',
        duration: '60 minutes',
        price: 'From ₱6,000',
        icon: Sun,
    },
    {
        slug: 'veneers',
        name: 'Veneers',
        description: 'Thin, custom shells that transform the shape, color, and alignment of your smile.',
        duration: '2 visits',
        price: 'From ₱10,000 per tooth',
        icon: Shield,
    },
    {
        slug: 'dentures',
        name: 'Dentures',
        description: 'Comfortable, custom-made replacements for missing teeth.',
        duration: 'Multiple visits',
        price: 'From ₱15,000',
        icon: Smile,
    },
    {
        slug: 'pediatric-dentistry',
        name: 'Pediatric Dentistry',
        description: "Gentle, kid-friendly dental care that builds healthy habits early.",
        duration: '30 minutes',
        price: 'From ₱1,000',
        icon: Baby,
    },
    {
        slug: 'general-consultation',
        name: 'General Consultation',
        description: 'A thorough check-up and personalized advice for your dental health.',
        duration: '20 minutes',
        price: 'From ₱500',
        icon: Stethoscope,
    },
];
```

- [ ] **Step 2: Create the dentists data**

```js
// resources/js/Data/dentists.js

export const dentists = [
    {
        slug: 'elena-santos',
        name: 'Dr. Elena Santos',
        specialty: 'General & Cosmetic Dentistry',
        bio: 'Dr. Santos has spent over a decade helping patients feel confident in their smiles, combining meticulous technique with a calm chairside manner.',
        credentials: ['DMD', 'Cosmetic Dentistry Cert.'],
        experience: '12 years of experience',
    },
    {
        slug: 'marcus-reyes',
        name: 'Dr. Marcus Reyes',
        specialty: 'Orthodontics',
        bio: 'Dr. Reyes specializes in creating well-aligned, healthy bites for patients of all ages, from first consultations through to retention.',
        credentials: ['DMD', 'M.S. Orthodontics'],
        experience: '9 years of experience',
    },
    {
        slug: 'priya-nair',
        name: 'Dr. Priya Nair',
        specialty: 'Pediatric Dentistry',
        bio: 'Dr. Nair makes visits easy for younger patients, focusing on gentle care and building positive dental habits that last a lifetime.',
        credentials: ['DMD', 'Pediatric Dentistry Cert.'],
        experience: '7 years of experience',
    },
];
```

- [ ] **Step 3: Create the testimonials data**

```js
// resources/js/Data/testimonials.js

export const testimonials = [
    {
        name: 'Angela M.',
        service: 'Teeth Whitening',
        quote: "I've never felt more comfortable at a dental clinic. The whole team explained every step and the results exceeded what I expected.",
    },
    {
        name: 'Rico D.',
        service: 'Root Canal Treatment',
        quote: "I was dreading this procedure, but Dr. Santos made it painless and quick. Genuinely the best dental experience I've had.",
    },
    {
        name: 'Josephine T.',
        service: 'Pediatric Dentistry',
        quote: 'My daughter actually looks forward to her check-ups now. Dr. Nair is wonderful with kids.',
    },
];
```

- [ ] **Step 4: Create the FAQ data**

```js
// resources/js/Data/faqs.js

export const faqs = [
    {
        question: 'What services do you offer?',
        answer: 'We provide general, cosmetic, and pediatric dentistry — from routine cleanings and fillings to braces, implants, and whitening. Visit our Services page for the full list.',
    },
    {
        question: 'How do I contact the clinic?',
        answer: 'Send us an inquiry through our Contact page, call us, or email us directly. Our team typically responds within one business day.',
    },
    {
        question: 'What should I bring to my first visit?',
        answer: 'Please bring a valid ID and any previous dental records or X-rays you have. If you have dental insurance, bring your card as well.',
    },
    {
        question: 'What are your clinic hours?',
        answer: "We're open Monday–Friday, 9:00 AM–6:00 PM, and Saturday, 9:00 AM–3:00 PM. We're closed on Sundays.",
    },
    {
        question: 'Where are you located?',
        answer: "We're located at 123 Harborview Ave, Makati City, Metro Manila. See our Contact page for directions.",
    },
];
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add static marketing content for services, dentists, testimonials, and FAQs

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Public UI kit — PublicLayout and shared components

**Files:**
- Modify: `package.json` (add `lucide-react`)
- Create: `resources/js/Layouts/PublicLayout.jsx`
- Create: `resources/js/Components/Public/Container.jsx`
- Create: `resources/js/Components/Public/Button.jsx`
- Create: `resources/js/Components/Public/SectionHeading.jsx`
- Create: `resources/js/Components/Public/Avatar.jsx`
- Create: `resources/js/Components/Public/FaqItem.jsx`
- Create: `resources/js/Components/Public/ServiceCard.jsx`
- Create: `resources/js/Components/Public/DentistCard.jsx`
- Create: `resources/js/Components/Public/TestimonialCard.jsx`
- Create: `resources/js/Components/Public/ContactInfo.jsx`

**Interfaces:**
- Consumes: `services` (Task 2, for the footer's service list).
- Produces: `PublicLayout` (default export, also named export `CLINIC` — `{name, address, phone, email, hours: [{days, time}]}`), `Container`, `Button` (`{href?, variant: 'primary'|'outline', ...props}`), `SectionHeading` (`{eyebrow?, title, subtitle?, align: 'center'|'left'}`), `Avatar` (`{name, size: 'md'|'lg'}`), `FaqItem` (`{question, answer}`), `ServiceCard` (`{service}`), `DentistCard` (`{dentist}`), `TestimonialCard` (`{testimonial}`), `ContactInfo` (no props — renders the address/phone/email/hours list using `CLINIC`). `ContactInfo` exists so Task 4's Home page and Task 8's Contact page don't each write out the same address/phone/email/hours markup — both need the identical block. All consumed by Tasks 4–8's pages.

No independent test — this is presentational infrastructure verified when Task 4's Home page renders it. Steps below are implementation only; the "run and check" step happens in Task 4.

- [ ] **Step 1: Install lucide-react**

```bash
npm install lucide-react
```

- [ ] **Step 2: Create `Container`**

```jsx
// resources/js/Components/Public/Container.jsx

export default function Container({ className = '', children }) {
    return <div className={`mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 ${className}`}>{children}</div>;
}
```

- [ ] **Step 3: Create `Button`**

```jsx
// resources/js/Components/Public/Button.jsx

import { Link } from '@inertiajs/react';

const VARIANTS = {
    primary: 'bg-teal-700 text-white hover:bg-teal-800',
    outline: 'border border-stone-300 text-stone-700 hover:bg-stone-50',
};

export default function Button({ href, variant = 'primary', className = '', children, ...props }) {
    const classes = `inline-flex items-center justify-center rounded-md px-5 py-2.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${VARIANTS[variant]} ${className}`;

    if (href) {
        return (
            <Link href={href} className={classes} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <button type="button" className={classes} {...props}>
            {children}
        </button>
    );
}
```

- [ ] **Step 4: Create `SectionHeading`**

```jsx
// resources/js/Components/Public/SectionHeading.jsx

export default function SectionHeading({ eyebrow, title, subtitle, align = 'center' }) {
    const alignment = align === 'left' ? 'text-left items-start' : 'text-center items-center mx-auto';

    return (
        <div className={`flex max-w-2xl flex-col gap-3 ${alignment}`}>
            {eyebrow && (
                <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                    {eyebrow}
                </span>
            )}
            <h2 className="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                {title}
            </h2>
            {subtitle && <p className="text-lg leading-relaxed text-stone-600">{subtitle}</p>}
        </div>
    );
}
```

- [ ] **Step 5: Create `Avatar`**

```jsx
// resources/js/Components/Public/Avatar.jsx

const PALETTE = ['bg-teal-100 text-teal-800', 'bg-amber-100 text-amber-800', 'bg-stone-200 text-stone-800'];

function initials(name) {
    return name
        .replace(/^Dr\.\s*/, '')
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export default function Avatar({ name, size = 'lg' }) {
    const sizes = { md: 'h-12 w-12 text-base', lg: 'h-20 w-20 text-2xl' };
    const colorIndex = name.length % PALETTE.length;

    return (
        <div
            className={`flex shrink-0 items-center justify-center rounded-full font-semibold ${sizes[size]} ${PALETTE[colorIndex]}`}
            role="img"
            aria-label={name}
        >
            {initials(name)}
        </div>
    );
}
```

- [ ] **Step 6: Create `FaqItem`**

```jsx
// resources/js/Components/Public/FaqItem.jsx

import { ChevronDown } from 'lucide-react';

export default function FaqItem({ question, answer }) {
    return (
        <details className="group border-b border-stone-200 py-4">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-left text-base font-medium text-stone-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                {question}
                <ChevronDown className="h-5 w-5 shrink-0 text-stone-500 transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <p className="mt-3 text-sm leading-relaxed text-stone-600">{answer}</p>
        </details>
    );
}
```

- [ ] **Step 7: Create `ServiceCard`**

```jsx
// resources/js/Components/Public/ServiceCard.jsx

import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

export default function ServiceCard({ service }) {
    const Icon = service.icon;

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-stone-200 bg-white p-6">
            <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                <Icon className="h-6 w-6" aria-hidden="true" />
            </div>
            <div>
                <h3 className="text-lg font-semibold text-stone-900">{service.name}</h3>
                <p className="mt-1 text-sm leading-relaxed text-stone-600">{service.description}</p>
            </div>
            <div className="mt-auto flex items-center justify-between pt-2 text-sm text-stone-500">
                <span>{service.duration}</span>
                <span className="font-medium text-stone-700">{service.price}</span>
            </div>
            <Link
                href={route('contact', { service: service.name })}
                className="inline-flex items-center gap-1 text-sm font-medium text-teal-700 hover:text-teal-800"
            >
                Inquire about this
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
        </div>
    );
}
```

- [ ] **Step 8: Create `DentistCard`**

```jsx
// resources/js/Components/Public/DentistCard.jsx

import Avatar from '@/Components/Public/Avatar';

export default function DentistCard({ dentist }) {
    return (
        <div className="flex flex-col items-center gap-4 rounded-lg border border-stone-200 bg-white p-8 text-center">
            <Avatar name={dentist.name} size="lg" />
            <div>
                <h3 className="text-lg font-semibold text-stone-900">{dentist.name}</h3>
                <p className="text-sm font-medium text-teal-700">{dentist.specialty}</p>
            </div>
            <p className="text-sm leading-relaxed text-stone-600">{dentist.bio}</p>
            <ul className="flex flex-wrap justify-center gap-2">
                {dentist.credentials.map((credential) => (
                    <li
                        key={credential}
                        className="rounded-full bg-stone-100 px-3 py-1 text-xs font-medium text-stone-600"
                    >
                        {credential}
                    </li>
                ))}
            </ul>
            <p className="text-xs text-stone-500">{dentist.experience}</p>
        </div>
    );
}
```

- [ ] **Step 9: Create `TestimonialCard`**

```jsx
// resources/js/Components/Public/TestimonialCard.jsx

import { Quote } from 'lucide-react';

export default function TestimonialCard({ testimonial }) {
    return (
        <figure className="flex flex-col gap-4 rounded-lg border border-stone-200 bg-white p-6">
            <Quote className="h-6 w-6 text-teal-700" aria-hidden="true" />
            <blockquote className="text-sm leading-relaxed text-stone-700">
                &ldquo;{testimonial.quote}&rdquo;
            </blockquote>
            <figcaption className="text-sm">
                <span className="font-semibold text-stone-900">{testimonial.name}</span>
                {testimonial.service && <span className="text-stone-500"> — {testimonial.service}</span>}
            </figcaption>
        </figure>
    );
}
```

- [ ] **Step 10: Create `ContactInfo`**

```jsx
// resources/js/Components/Public/ContactInfo.jsx

import { MapPin, Phone, Mail, Clock } from 'lucide-react';
import { CLINIC } from '@/Layouts/PublicLayout';

export default function ContactInfo() {
    return (
        <ul className="flex flex-col gap-3 text-stone-600">
            <li className="flex items-center gap-3">
                <MapPin className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.address}
            </li>
            <li className="flex items-center gap-3">
                <Phone className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.phone}
            </li>
            <li className="flex items-center gap-3">
                <Mail className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.email}
            </li>
            <li className="flex items-start gap-3">
                <Clock className="mt-0.5 h-5 w-5 text-teal-700" aria-hidden="true" />
                <span>
                    {CLINIC.hours.map((h) => (
                        <span key={h.days} className="block">
                            {h.days}: {h.time}
                        </span>
                    ))}
                </span>
            </li>
        </ul>
    );
}
```

`PublicLayout`'s own footer (Step 11 below) keeps its own smaller, four-column-grid contact block inline — it uses different icon sizes and denser spacing than this component, so forcing it into `ContactInfo` would need a prop just to change sizing for one caller. `ContactInfo` is for the two places that render the block identically: Home's "Visit us" section (Task 4) and the Contact page's info column (Task 8).

- [ ] **Step 11: Create `PublicLayout`**

```jsx
// resources/js/Layouts/PublicLayout.jsx

import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Menu, X, MapPin, Phone, Mail, Clock } from 'lucide-react';
import { services } from '@/Data/services';

const NAV_LINKS = [
    { name: 'home', label: 'Home' },
    { name: 'services', label: 'Services' },
    { name: 'dentists', label: 'Dentists' },
    { name: 'about', label: 'About' },
    { name: 'contact', label: 'Contact' },
];

export const CLINIC = {
    name: 'Harborview Dental Clinic',
    address: '123 Harborview Ave, Makati City, Metro Manila',
    phone: '(02) 8123 4567',
    email: 'hello@harborviewdental.example',
    hours: [
        { days: 'Monday – Friday', time: '9:00 AM – 6:00 PM' },
        { days: 'Saturday', time: '9:00 AM – 3:00 PM' },
        { days: 'Sunday', time: 'Closed' },
    ],
};

export default function PublicLayout({ children }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="flex min-h-screen flex-col bg-white text-stone-900">
            <header className="sticky top-0 z-40 border-b border-stone-200 bg-white/90 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <Link href={route('home')} className="text-lg font-semibold tracking-tight text-stone-900">
                        {CLINIC.name}
                    </Link>

                    <nav className="hidden items-center gap-8 md:flex">
                        {NAV_LINKS.filter((l) => l.name !== 'contact').map((link) => (
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
                            href={route('contact')}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-teal-800"
                        >
                            Contact
                        </Link>
                    </nav>

                    <button
                        type="button"
                        aria-expanded={mobileOpen}
                        aria-controls="mobile-nav"
                        aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                        onClick={() => setMobileOpen((open) => !open)}
                        className="inline-flex items-center justify-center rounded-md p-2 text-stone-600 hover:bg-stone-100 md:hidden"
                    >
                        {mobileOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
                    </button>
                </div>

                {mobileOpen && (
                    <nav id="mobile-nav" className="border-t border-stone-200 bg-white md:hidden">
                        <div className="space-y-1 px-4 py-3">
                            {NAV_LINKS.map((link) => (
                                <Link
                                    key={link.name}
                                    href={route(link.name)}
                                    onClick={() => setMobileOpen(false)}
                                    className={`block rounded-md px-3 py-2 text-base font-medium ${
                                        route().current(link.name) ? 'bg-teal-50 text-teal-700' : 'text-stone-700 hover:bg-stone-50'
                                    }`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    </nav>
                )}
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-stone-200 bg-stone-50">
                <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-4 lg:px-8">
                    <div>
                        <p className="text-lg font-semibold text-stone-900">{CLINIC.name}</p>
                        <p className="mt-2 text-sm leading-relaxed text-stone-600">
                            Modern, patient-centered dental care in a calm, welcoming environment.
                        </p>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Navigation</p>
                        <ul className="mt-3 space-y-2">
                            {NAV_LINKS.map((link) => (
                                <li key={link.name}>
                                    <Link href={route(link.name)} className="text-sm text-stone-600 hover:text-teal-700">
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Services</p>
                        <ul className="mt-3 space-y-2">
                            {services.slice(0, 5).map((service) => (
                                <li key={service.slug} className="text-sm text-stone-600">
                                    {service.name}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Contact</p>
                        <ul className="mt-3 space-y-2 text-sm text-stone-600">
                            <li className="flex items-start gap-2">
                                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.address}
                            </li>
                            <li className="flex items-center gap-2">
                                <Phone className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.phone}
                            </li>
                            <li className="flex items-center gap-2">
                                <Mail className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.email}
                            </li>
                            <li className="flex items-start gap-2">
                                <Clock className="mt-0.5 h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                <span>
                                    {CLINIC.hours.map((h) => (
                                        <span key={h.days} className="block">
                                            {h.days}: {h.time}
                                        </span>
                                    ))}
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div className="border-t border-stone-200 py-6 text-center text-sm text-stone-500">
                    &copy; {new Date().getFullYear()} {CLINIC.name}. All rights reserved.
                </div>
            </footer>
        </div>
    );
}
```

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "Add PublicLayout and shared public-site components

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: PublicSiteController, routing, and the Home page

**Files:**
- Create: `app/Http/Controllers/PublicSiteController.php`
- Create: `resources/js/Pages/Public/Home.jsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `Button`, `SectionHeading`, `ServiceCard`, `DentistCard`, `TestimonialCard`, `FaqItem`, `ContactInfo` (Task 3); `services`, `dentists`, `testimonials`, `faqs` (Task 2).
- Produces: route names `home`, `services`, `dentists`, `about`, `contact` (all GET). Tasks 5–8 rely on these routes already existing and only need to add the page component each one renders.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PublicPagesTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: FAIL — `route('home')` undefined.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/PublicSiteController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicSiteController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Public/Home');
    }

    public function services(): Response
    {
        return Inertia::render('Public/Services');
    }

    public function dentists(): Response
    {
        return Inertia::render('Public/Dentists');
    }

    public function about(): Response
    {
        return Inertia::render('Public/About');
    }

    public function contact(Request $request): Response
    {
        return Inertia::render('Public/Contact', [
            'initialService' => $request->query('service'),
        ]);
    }
}
```

- [ ] **Step 4: Replace the root redirect with public routes**

In `routes/web.php`, remove this line:

```php
Route::redirect('/', '/dashboard');
```

and the two comment lines above it explaining the old behavior. Replace with:

```php
use App\Http\Controllers\PublicSiteController;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/services', [PublicSiteController::class, 'services'])->name('services');
Route::get('/dentists', [PublicSiteController::class, 'dentists'])->name('dentists');
Route::get('/about', [PublicSiteController::class, 'about'])->name('about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('contact');
```

- [ ] **Step 5: Run the test — confirm PASS**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: PASS.

- [ ] **Step 6: Build the Home page**

```jsx
// resources/js/Pages/Public/Home.jsx

import { Head } from '@inertiajs/react';
import PublicLayout, { CLINIC } from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import Button from '@/Components/Public/Button';
import SectionHeading from '@/Components/Public/SectionHeading';
import ServiceCard from '@/Components/Public/ServiceCard';
import DentistCard from '@/Components/Public/DentistCard';
import TestimonialCard from '@/Components/Public/TestimonialCard';
import FaqItem from '@/Components/Public/FaqItem';
import ContactInfo from '@/Components/Public/ContactInfo';
import { services } from '@/Data/services';
import { dentists } from '@/Data/dentists';
import { testimonials } from '@/Data/testimonials';
import { faqs } from '@/Data/faqs';
import { ShieldCheck, HeartPulse, Building2, UserCheck, CalendarCheck } from 'lucide-react';

const WHY_CHOOSE_US = [
    {
        icon: ShieldCheck,
        title: 'Experienced professionals',
        description: 'Our dentists bring years of hands-on experience across general, cosmetic, and pediatric care.',
    },
    {
        icon: HeartPulse,
        title: 'Patient-centered care',
        description: 'We take time to listen and explain every step, so you always know what to expect.',
    },
    {
        icon: Building2,
        title: 'Modern facilities',
        description: 'Our clinic is equipped with up-to-date technology in a clean, comfortable space.',
    },
    {
        icon: UserCheck,
        title: 'Personalized treatment',
        description: 'Every treatment plan is tailored to your specific needs, not a one-size-fits-all approach.',
    },
    {
        icon: CalendarCheck,
        title: 'Convenient scheduling',
        description: 'Flexible hours and a responsive front desk make it easy to find a time that works for you.',
    },
];

export default function Home() {
    return (
        <PublicLayout>
            <Head title="Home" />

            <section className="border-b border-stone-200 bg-stone-50">
                <Container className="grid gap-12 py-20 sm:py-28 lg:grid-cols-2 lg:items-center">
                    <div className="flex flex-col items-start gap-6">
                        <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                            {CLINIC.name}
                        </span>
                        <h1 className="text-4xl font-semibold tracking-tight text-stone-900 sm:text-5xl">
                            Dental care that puts you at ease.
                        </h1>
                        <p className="max-w-xl text-lg leading-relaxed text-stone-600">
                            From routine cleanings to complete smile makeovers, our team provides gentle,
                            modern dental care in a calm, welcoming environment.
                        </p>
                        <Button href={route('contact')}>Contact Us</Button>
                    </div>

                    <div className="relative mx-auto h-64 w-64 sm:h-80 sm:w-80" aria-hidden="true">
                        <div className="absolute inset-0 rounded-full bg-teal-100" />
                        <div className="absolute inset-8 rounded-full bg-teal-200/70" />
                        <div className="absolute inset-16 flex items-center justify-center rounded-full bg-white shadow-sm">
                            <svg viewBox="0 0 24 24" className="h-16 w-16 text-teal-700" fill="none" stroke="currentColor" strokeWidth="1.5">
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M8 10c1.5-3 2.5-3 4-3s2.5 0 4 3c1 2-.5 3-1 5-.4 1.6-.5 4-2 4s-1.2-2.7-2-2.7S9.4 19 8 19c-1.5 0-1.6-2.4-2-4-.5-2-2-3-1-5Z"
                                />
                            </svg>
                        </div>
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Services"
                        title="Comprehensive dental care"
                        subtitle="From everyday check-ups to advanced procedures, we offer the full range of dental services under one roof."
                    />
                    <div className="grid w-full gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {services.slice(0, 6).map((service) => (
                            <ServiceCard key={service.slug} service={service} />
                        ))}
                    </div>
                    <Button href={route('services')} variant="outline">
                        View All Services
                    </Button>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Why Harborview" title="Why choose us" />
                    <div className="grid w-full gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        {WHY_CHOOSE_US.map((item) => {
                            const Icon = item.icon;
                            return (
                                <div key={item.title} className="flex flex-col gap-3">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                                        <Icon className="h-6 w-6" aria-hidden="true" />
                                    </div>
                                    <h3 className="text-base font-semibold text-stone-900">{item.title}</h3>
                                    <p className="text-sm leading-relaxed text-stone-600">{item.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Our Team"
                        title="Meet our dentists"
                        subtitle="A team of experienced, approachable dental professionals dedicated to your care."
                    />
                    <div className="grid w-full gap-8 sm:grid-cols-3">
                        {dentists.map((dentist) => (
                            <DentistCard key={dentist.slug} dentist={dentist} />
                        ))}
                    </div>
                    <Button href={route('dentists')} variant="outline">
                        Meet Our Dentists
                    </Button>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Testimonials" title="What our patients say" />
                    <div className="grid w-full gap-6 sm:grid-cols-3">
                        {testimonials.map((testimonial) => (
                            <TestimonialCard key={testimonial.name} testimonial={testimonial} />
                        ))}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-2">
                    <SectionHeading eyebrow="FAQ" title="Frequently asked questions" />
                    <div className="mt-8">
                        {faqs.map((faq) => (
                            <FaqItem key={faq.question} question={faq.question} answer={faq.answer} />
                        ))}
                    </div>
                </Container>
            </section>

            <section className="border-t border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="grid gap-10 lg:grid-cols-2 lg:items-center">
                    <div className="flex flex-col gap-4">
                        <h2 className="text-3xl font-semibold tracking-tight text-stone-900">Visit us</h2>
                        <ContactInfo />
                    </div>

                    <div className="flex flex-col items-start gap-4 rounded-lg border border-stone-200 bg-white p-8">
                        <h3 className="text-xl font-semibold text-stone-900">Have a question?</h3>
                        <p className="text-sm leading-relaxed text-stone-600">
                            Send us your inquiry and our clinic team will get back to you.
                        </p>
                        <Button href={route('contact')}>Contact Us</Button>
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 7: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/` as a guest. Confirm: hero renders, all sections appear in order, mobile menu opens/closes (resize browser or use device toolbar), "Contact Us" / "View All Services" / "Meet Our Dentists" links navigate (they'll 404 until Tasks 5–6 land — that's expected at this point). Stop the server.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add PublicSiteController, public routing, and the Home page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Services page

**Files:**
- Create: `resources/js/Pages/Public/Services.jsx`
- Modify: `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `SectionHeading`, `ServiceCard` (Task 3); `services` (Task 2); `services` route (Task 4).

- [ ] **Step 1: Add the failing test**

Add to `tests/Feature/PublicPagesTest.php`:

```php
    public function test_services_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('services'));

        $response->assertOk();
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: the new test passes at the HTTP level already (the route exists from Task 4), but the page will error client-side without a component — this step is a formality confirming the route responds; proceed to Step 3 regardless.

- [ ] **Step 3: Build the Services page**

```jsx
// resources/js/Pages/Public/Services.jsx

import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ServiceCard from '@/Components/Public/ServiceCard';
import { services } from '@/Data/services';

export default function Services() {
    return (
        <PublicLayout>
            <Head title="Services" />

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Services"
                        title="Our dental services"
                        subtitle="Comprehensive care for every stage of your dental health, from routine visits to advanced procedures."
                    />
                    <div className="grid w-full gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {services.map((service) => (
                            <ServiceCard key={service.slug} service={service} />
                        ))}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 4: Run the test suite — confirm PASS**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

Expected: both tests pass.

- [ ] **Step 5: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/services`, confirm all 12 cards render with icon/name/description/duration/price, "Inquire about this" links to `/contact?service=<name>`. Stop the server.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add Services page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Dentists page

**Files:**
- Create: `resources/js/Pages/Public/Dentists.jsx`
- Modify: `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `SectionHeading`, `DentistCard` (Task 3); `dentists` (Task 2); `dentists` route (Task 4).

- [ ] **Step 1: Add the failing test**

Add to `tests/Feature/PublicPagesTest.php`:

```php
    public function test_dentists_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('dentists'));

        $response->assertOk();
    }
```

- [ ] **Step 2: Build the Dentists page**

```jsx
// resources/js/Pages/Public/Dentists.jsx

import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import DentistCard from '@/Components/Public/DentistCard';
import { dentists } from '@/Data/dentists';

export default function Dentists() {
    return (
        <PublicLayout>
            <Head title="Dentists" />

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Our Team"
                        title="Meet our dentists"
                        subtitle="An experienced, approachable team dedicated to your comfort and care."
                    />
                    <div className="grid w-full gap-8 sm:grid-cols-3">
                        {dentists.map((dentist) => (
                            <DentistCard key={dentist.slug} dentist={dentist} />
                        ))}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 3: Run the test suite — confirm PASS**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

- [ ] **Step 4: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/dentists`, confirm all 3 profiles render with initials avatar, name, specialty, bio, credentials, experience. Stop the server.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add Dentists page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: About page

**Files:**
- Create: `resources/js/Pages/Public/About.jsx`
- Modify: `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `SectionHeading` (Task 3); `about` route (Task 4).

- [ ] **Step 1: Add the failing test**

Add to `tests/Feature/PublicPagesTest.php`:

```php
    public function test_about_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('about'));

        $response->assertOk();
    }
```

- [ ] **Step 2: Build the About page**

```jsx
// resources/js/Pages/Public/About.jsx

import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import { HeartPulse, Target, Sparkles, Building2 } from 'lucide-react';

const VALUES = [
    { icon: HeartPulse, title: 'Compassion', description: 'We treat every patient with warmth, patience, and respect.' },
    { icon: Target, title: 'Precision', description: 'We hold our clinical work to a high, consistent standard.' },
    { icon: Sparkles, title: 'Comfort', description: 'We design every visit to feel calm, not clinical.' },
    { icon: Building2, title: 'Community', description: "We're proud to be a trusted part of the neighborhoods we serve." },
];

export default function About() {
    return (
        <PublicLayout>
            <Head title="About" />

            <section className="py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-6 text-center">
                    <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">Our Story</span>
                    <h1 className="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                        Dentistry built around people, not procedures.
                    </h1>
                    <p className="text-lg leading-relaxed text-stone-600">
                        Harborview Dental Clinic was founded to offer a different kind of dental visit —
                        one where patients feel heard, informed, and comfortable from the moment they walk
                        in. What started as a small practice has grown into a full-service clinic, but our
                        approach hasn't changed: careful, personalized care in a calm environment.
                    </p>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-3">
                        <h2 className="text-2xl font-semibold text-stone-900">Mission</h2>
                        <p className="leading-relaxed text-stone-600">
                            To provide accessible, high-quality dental care that helps every patient
                            maintain a healthy, confident smile for life.
                        </p>
                    </div>
                    <div className="flex flex-col gap-3">
                        <h2 className="text-2xl font-semibold text-stone-900">Vision</h2>
                        <p className="leading-relaxed text-stone-600">
                            To be the clinic our community trusts first — known for gentle care, modern
                            treatment, and lasting patient relationships.
                        </p>
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Values" title="What we stand for" />
                    <div className="grid w-full gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        {VALUES.map((value) => {
                            const Icon = value.icon;
                            return (
                                <div key={value.title} className="flex flex-col items-center gap-3 text-center">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                                        <Icon className="h-6 w-6" aria-hidden="true" />
                                    </div>
                                    <h3 className="text-base font-semibold text-stone-900">{value.title}</h3>
                                    <p className="text-sm leading-relaxed text-stone-600">{value.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </Container>
            </section>

            <section className="border-t border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-4 text-center">
                    <h2 className="text-2xl font-semibold text-stone-900">Our facilities</h2>
                    <p className="leading-relaxed text-stone-600">
                        Our clinic is equipped with modern dental technology in a clean, welcoming space —
                        designed to make every visit as comfortable as possible, for patients of every age.
                    </p>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 3: Run the test suite — confirm PASS**

```bash
php artisan test tests/Feature/PublicPagesTest.php
```

- [ ] **Step 4: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/about`, confirm story/mission/vision/values/facilities sections render. Stop the server.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add About page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: Contact page

**Files:**
- Create: `resources/js/Pages/Public/Contact.jsx`
- Modify: `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- Consumes: `PublicLayout`, `Container`, `SectionHeading`, `ContactInfo` (Task 3); `contact` route with `initialService` prop, `inquiries.store` route (Tasks 1 and 4).

- [ ] **Step 1: Add the failing test**

Add to `tests/Feature/PublicPagesTest.php`:

```php
    public function test_contact_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('contact'));

        $response->assertOk();
    }
```

- [ ] **Step 2: Build the Contact page**

```jsx
// resources/js/Pages/Public/Contact.jsx

import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ContactInfo from '@/Components/Public/ContactInfo';
import { CheckCircle2 } from 'lucide-react';

const inputClass =
    'mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-teal-700 focus:outline-none focus:ring-1 focus:ring-teal-700';

export default function Contact({ initialService }) {
    const [submitted, setSubmitted] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        phone: '',
        service_interest: initialService ?? '',
        message: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('inquiries.store'), {
            onSuccess: () => {
                reset();
                setSubmitted(true);
            },
        });
    }

    return (
        <PublicLayout>
            <Head title="Contact" />

            <section className="py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-8">
                        <SectionHeading
                            align="left"
                            eyebrow="Contact"
                            title="Get in touch"
                            subtitle="Send us your inquiry and our clinic team will get back to you."
                        />

                        <ContactInfo />
                    </div>

                    <div className="rounded-lg border border-stone-200 bg-white p-8">
                        {submitted ? (
                            <div className="flex flex-col items-center gap-3 py-8 text-center">
                                <CheckCircle2 className="h-10 w-10 text-teal-700" aria-hidden="true" />
                                <h3 className="text-lg font-semibold text-stone-900">Thank you!</h3>
                                <p className="text-sm leading-relaxed text-stone-600">
                                    Thanks — our clinic team will get back to you shortly.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
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
                                        Phone <span className="text-stone-400">(optional)</span>
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
                                    <label htmlFor="service_interest" className="block text-sm font-medium text-stone-700">
                                        Service of interest <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <input
                                        id="service_interest"
                                        type="text"
                                        value={data.service_interest}
                                        onChange={(e) => setData('service_interest', e.target.value)}
                                        className={inputClass}
                                    />
                                </div>

                                <div>
                                    <label htmlFor="message" className="block text-sm font-medium text-stone-700">
                                        Message
                                    </label>
                                    <textarea
                                        id="message"
                                        rows={4}
                                        value={data.message}
                                        onChange={(e) => setData('message', e.target.value)}
                                        aria-describedby={errors.message ? 'message-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.message && (
                                        <p id="message-error" className="mt-1 text-sm text-red-600">
                                            {errors.message}
                                        </p>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-md bg-teal-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Sending…' : 'Send Inquiry'}
                                </button>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}
```

- [ ] **Step 3: Run the full backend suite — confirm nothing broke**

```bash
php artisan test
```

Expected: every test across every task so far passes.

- [ ] **Step 4: Manual check**

```bash
npm run build
php artisan serve
```

Visit `/contact`. Submit the form with all fields blank — confirm inline validation errors appear under Name, Email, Message. Fill it in and submit — confirm the button reads "Sending…" while processing, then the form is replaced with the thank-you panel (not just cleared) so a second submit isn't possible without reloading. Visit `/services`, click "Inquire about this" on a service, confirm `/contact` pre-fills "Service of interest" with that service's name. Stop the server.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add Contact page with inquiry form

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 9: Staff-facing Inquiries page

**Files:**
- Create: `resources/js/Pages/Admin/Inquiries/Index.jsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`

**Interfaces:**
- Consumes: `Badge` (existing component); `inquiries.index`/`inquiries.update` routes and their JSON shape (Task 1).

No new backend logic — `inquiries.index`/`inquiries.update` are already tested in Task 1. Verification here is manual.

- [ ] **Step 1: Build the Inquiries page**

```jsx
// resources/js/Pages/Admin/Inquiries/Index.jsx

import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Badge from '@/Components/Badge';

export default function Index({ inquiries }) {
    function markRead(inquiry) {
        router.patch(route('inquiries.update', inquiry.id), { read: true }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inquiries</h2>}>
            <Head title="Inquiries" />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white shadow rounded divide-y">
                    {inquiries.map((inquiry) => (
                        <div key={inquiry.id} className="flex items-start justify-between gap-4 p-4">
                            <div>
                                <div className="font-medium">{inquiry.name}</div>
                                <div className="text-sm text-gray-500">{inquiry.email}</div>
                                {inquiry.service_interest && (
                                    <div className="text-sm text-gray-500">{inquiry.service_interest}</div>
                                )}
                                <p className="mt-1 text-sm text-gray-700">{inquiry.message}</p>
                                <div className="mt-1 text-xs text-gray-400">{inquiry.created_at}</div>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <Badge tone={inquiry.read_at ? 'muted' : 'warn'}>
                                    {inquiry.read_at ? 'Read' : 'New'}
                                </Badge>
                                {!inquiry.read_at && (
                                    <button onClick={() => markRead(inquiry)} className="text-sm text-blue-600">
                                        Mark as read
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                    {inquiries.length === 0 && (
                        <div className="p-4 text-sm text-gray-500">No inquiries yet.</div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Add the nav link**

In `resources/js/Layouts/AuthenticatedLayout.jsx`, in the desktop nav block, immediately after the "Appointments" `<NavLink>`:

```jsx
<NavLink
    href={route('inquiries.index')}
    active={route().current('inquiries.index')}
>
    Inquiries
</NavLink>
```

In the mobile nav block, immediately after the "Appointments" `<ResponsiveNavLink>`:

```jsx
<ResponsiveNavLink
    href={route('inquiries.index')}
    active={route().current('inquiries.index')}
>
    Inquiries
</ResponsiveNavLink>
```

- [ ] **Step 3: Manual check**

```bash
npm run build
php artisan serve
```

Log in, submit a test inquiry via `/contact` in a private/incognito window (or log out first), then log back in and visit `/inquiries`. Confirm the submission appears with a "New" badge, click "Mark as read", confirm the badge flips to "Read" and the button disappears. Confirm "Inquiries" appears in both desktop and mobile nav. Stop the server.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Add staff-facing Inquiries page and nav link

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 10: Final QA and regression check

**Files:** none (verification only).

**Interfaces:** none — this task consumes everything built in Tasks 1–9 and confirms it together.

- [ ] **Step 1: Run the full automated test suite**

```bash
php artisan test
```

Expected: every test across every task — v1's and this phase's — passes.

- [ ] **Step 2: Production build**

```bash
npm run build
```

Expected: builds cleanly, no errors or warnings about missing modules.

- [ ] **Step 3: Full manual walkthrough**

```bash
php artisan serve
```

As a guest:
- Visit `/`, `/services`, `/dentists`, `/about`, `/contact` — confirm each renders fully (not just 200 OK).
- Resize to a mobile width (or use browser device toolbar) and re-check: header collapses to a hamburger menu that opens/closes, all sections stack sensibly, the contact form remains usable, footer readable.
- Tab through the Contact page with keyboard only — confirm every link, nav item, form field, and the FAQ accordion on the Home page are reachable and show a visible focus ring.
- Submit the contact form with missing fields, confirm validation messages; submit successfully, confirm the thank-you panel appears and the button was disabled while processing.

As a logged-in staff user:
- Visit `/login`, log in, confirm you land on `/dashboard` exactly as before this phase.
- Confirm `/patients`, `/providers`, `/appointments` all still work exactly as before — this phase must not have changed their behavior.
- Visit `/inquiries`, confirm the submission from the guest walkthrough above appears.
- Confirm the internal app's header/nav still looks like the pre-existing `AuthenticatedLayout` (no public-site styling leaked in) and vice versa — visit `/` while logged in and confirm it shows the public homepage, not a redirect to `/dashboard`.

Stop the server once all of the above is confirmed.

- [ ] **Step 4: Commit**

If Step 3 surfaced no fixes, there's nothing to commit for this task — it's a verification pass. If any fix was needed, commit it now:

```bash
git add -A
git commit -m "Fix issues found in Phase 2 final QA

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Plan self-review notes

- **Spec coverage:** Inquiry backend + staff listing (Task 1), static content (Task 2), PublicLayout/nav/footer/mobile nav (Task 3), routing + Home (Task 4), Services/Dentists/About (Tasks 5–7), Contact form with success/duplicate-prevention behavior (Task 8), staff Inquiries UX (Task 9), accessibility/responsive/regression verification (Task 10). Every section of `docs/superpowers/specs/2026-08-24-public-website-design.md` maps to a task.
- **Placeholder scan:** no TBD/TODO; every step has real, complete code.
- **Type consistency:** `Inquiry`'s fillable fields (`name`, `email`, `phone`, `service_interest`, `message`, `read_at`) are identical across the migration (Task 1), model (Task 1), factory (Task 1), `InquiryController@store`'s validation (Task 1), and `Contact.jsx`'s form fields (Task 8) — no drift. `PublicLayout`'s exported `CLINIC` shape (`name`, `address`, `phone`, `email`, `hours: [{days, time}]`) is defined once (Task 3) and consumed identically in `Home.jsx` (Task 4) and `Contact.jsx` (Task 8). Route names (`home`, `services`, `dentists`, `about`, `contact`, `inquiries.store`, `inquiries.index`, `inquiries.update`) are defined once each in `routes/web.php` (Tasks 1 and 4) and referenced by those exact names everywhere else.
- **Deviation from the spec:** none identified — the `service_interest` query-param pass-through (`ServiceCard` → `contact` route → `PublicSiteController@contact` → `initialService` prop → form default) implements the spec's "communicated through the UI if practical" language as a concrete mechanism.
- **Pre-flight fix (2026-08-24, before dispatch):** the original draft had Home.jsx and Contact.jsx each writing out the identical address/phone/email/hours block inline — verbatim duplication a code-quality reviewer would flag. Extracted `ContactInfo` (Task 3, Step 10) and updated both consuming pages (Tasks 4 and 8) to render it instead. `PublicLayout`'s own footer keeps its separate, more compact inline version since its icon sizing and spacing genuinely differ — noted in Task 3 so a reviewer doesn't flag that as missed reuse.
