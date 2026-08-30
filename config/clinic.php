<?php

use Carbon\Carbon;

return [

    /*
     * Weekdays the clinic is closed, as Carbon day-of-week integers
     * (Carbon::SUNDAY === 0). This is the authority for booking-date
     * validation on both the server and the booking form — the CLINIC.hours
     * constant in PublicLayout.jsx is display copy only.
     */
    'closed_days' => [Carbon::SUNDAY],

    /*
     * How many appointments (requested or scheduled) a single
     * date + time-of-day slot may hold before the public booking form
     * rejects further requests for it. Clinic-wide, not per-dentist —
     * the public dentist picker isn't linked to real Provider rows.
     */
    'max_requests_per_slot' => 6,

    /*
     * The clock time, in the app's configured timezone, that separates
     * "morning" from "afternoon" when bucketing a scheduled appointment's
     * real start_time for slot-capacity counting.
     */
    'afternoon_starts_at' => '12:00',

    /*
     * Contact details for outbound mail (app/Mail/*). Duplicates
     * PublicLayout.jsx's CLINIC.phone/email, which is the source of truth
     * for the public site's display copy — this is the backend's only
     * equivalent, since transactional mail is rendered server-side and
     * can't reach into the frontend bundle. Keep the two in sync when
     * either changes; unifying them would need the frontend to receive
     * clinic identity as a shared Inertia prop instead of a JS constant.
     */
    'contact_phone' => '(02) 8123 4567',
    'contact_email' => 'hello@harborviewdental.example',

    /*
     * The canonical values a public booking may carry for
     * `service_interest` and `dentist_preference`. These are the authority
     * on both sides: PublicSiteController::book() passes them to the
     * booking form as Inertia props, so the <select> a guest sees and the
     * Rule::in() the server enforces cannot drift apart.
     *
     * Without this the fields accepted 255 characters of anything, and a
     * booking against a known patient's email appends to that patient's
     * record — so attacker-authored text was rendered back to them on
     * their own signed lookup page inside the clinic's branded UI.
     *
     * resources/js/Data/services.js and dentists.js keep the richer
     * marketing content (description, price, bio) for the services and
     * dentists pages. Related data, but not the same data — keep the names
     * here in step with the names there.
     */
    'bookable_services' => [
        'Dental Cleaning',
        'Dental Fillings',
        'Tooth Extraction',
        'Root Canal Treatment',
        'Dental Crowns',
        'Dental Implants',
        'Braces',
        'Teeth Whitening',
        'Veneers',
        'Dentures',
        'Pediatric Dentistry',
        'General Consultation',
    ],

    'bookable_dentists' => [
        'Dr. Elena Santos',
        'Dr. Marcus Reyes',
        'Dr. Priya Nair',
    ],

    /*
     * How far ahead a guest may request an appointment. Without an upper
     * bound, '9999-12-31' validates and a slot arbitrarily far in the
     * future can be poisoned.
     */
    'max_booking_days_ahead' => 180,

    /*
     * The shared code a new staff member must supply at /register.
     * Empty or unset disables self-registration entirely — GET and POST
     * /register both 403. A real deployment sets this to a strong value,
     * shares it out of band with incoming staff, and may blank it again
     * once onboarding is finished.
     */
    'registration_code' => env('REGISTRATION_CODE'),

];
