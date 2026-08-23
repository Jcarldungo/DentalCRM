<?php
// database/seeders/DemoSeeder.php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $providers = Provider::factory()->count(3)->create();

        // A patient overdue for recall under the default 6-month interval.
        $overdue = Patient::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'recall_interval_months' => null,
        ]);
        Appointment::factory()->create([
            'patient_id' => $overdue->id,
            'provider_id' => $providers->first()->id,
            'type' => 'cleaning',
            'status' => 'completed',
            'start_time' => Carbon::now()->subMonths(8),
            'end_time' => Carbon::now()->subMonths(8)->addMinutes(30),
        ]);

        // A patient recently cleaned, not yet due.
        $current = Patient::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
        Appointment::factory()->create([
            'patient_id' => $current->id,
            'provider_id' => $providers->first()->id,
            'type' => 'cleaning',
            'status' => 'completed',
            'start_time' => Carbon::now()->subMonths(1),
            'end_time' => Carbon::now()->subMonths(1)->addMinutes(30),
        ]);

        // A handful more patients and upcoming appointments to populate the calendar.
        Patient::factory()->count(8)->create()->each(function (Patient $patient) use ($providers) {
            Appointment::factory()->create([
                'patient_id' => $patient->id,
                'provider_id' => $providers->random()->id,
                'start_time' => Carbon::now()->addDays(rand(1, 14))->setTime(rand(9, 16), 0),
                'end_time' => Carbon::now()->addDays(rand(1, 14))->setTime(rand(9, 16), 30),
            ]);
        });
    }
}
