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

];
