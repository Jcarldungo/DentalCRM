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

];
