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

];
