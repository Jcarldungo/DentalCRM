<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily, end of business day, clinic time (APP_TIMEZONE) — patients get
// tomorrow's reminder the evening before.
Schedule::command('appointments:send-reminders')->dailyAt('17:00');
