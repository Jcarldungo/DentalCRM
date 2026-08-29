<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
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
            $day = Carbon::now()->addDays(rand(1, 14))->setTime(rand(9, 16), 0);

            Appointment::factory()->create([
                'patient_id' => $patient->id,
                'provider_id' => $providers->random()->id,
                'start_time' => $day,
                'end_time' => (clone $day)->addMinutes(30),
            ]);
        });

        // --- Reporting fixtures: ~120 days of billed activity so /reports
        //     is populated on a fresh seed. Additive; nothing above changes.
        $staff = User::factory()->create();
        $allPatients = Patient::all();
        $types = ['checkup', 'cleaning', 'procedure', 'other'];
        $methods = ['cash', 'card', 'bank_transfer'];

        foreach (range(1, 30) as $i) {
            $day = Carbon::now()->subDays(rand(3, 118))->setTime(rand(9, 16), [0, 30][rand(0, 1)]);
            $status = ['completed', 'completed', 'completed', 'cancelled', 'no_show', 'scheduled'][rand(0, 5)];

            Appointment::factory()->create([
                'patient_id' => $allPatients->random()->id,
                'provider_id' => $providers->random()->id,
                'type' => $types[array_rand($types)],
                'status' => $status,
                'start_time' => $day,
                'end_time' => (clone $day)->addMinutes(30),
            ]);
        }

        foreach (range(1, 15) as $i) {
            $patient = $allPatients->random();
            $provider = $providers->random();
            $issuedAt = Carbon::now()->subDays(rand(3, 115));

            $tpi = rand(0, 1) === 1
                ? TreatmentPlanItem::factory()->create([
                    'patient_id' => $patient->id,
                    'provider_id' => $provider->id,
                    'treatment' => ['Dental Cleaning', 'Composite Filling', 'Root Canal Treatment', 'Crown'][rand(0, 3)],
                    'created_by' => $staff->id,
                ])
                : null;

            $invoice = Invoice::factory()->issued()->create([
                'patient_id' => $patient->id,
                'discount_amount' => [0, 0, 250][rand(0, 2)],
                'issued_at' => $issuedAt,
                'created_by' => $staff->id,
            ]);

            $lineTotal = 0;
            foreach (range(1, rand(1, 3)) as $line) {
                $amount = rand(6, 40) * 100;
                $lineTotal += $amount;
                InvoiceItem::factory()->create([
                    'invoice_id' => $invoice->id,
                    'treatment_plan_item_id' => $line === 1 ? $tpi?->id : null,
                    'provider_id' => $line === 1 ? ($tpi?->provider_id ?? $provider->id) : null,
                    'amount' => $amount,
                ]);
            }

            // 0 = unpaid, 1 = partial, 2 = paid in full
            $payLevel = rand(0, 2);
            if ($payLevel > 0) {
                $pay = $payLevel === 2 ? $lineTotal - (int) $invoice->discount_amount : (int) ($lineTotal * 0.5);
                Payment::factory()->create([
                    'invoice_id' => $invoice->id,
                    'amount' => $pay,
                    'method' => $methods[array_rand($methods)],
                    'paid_on' => $issuedAt->clone()->addDays(rand(0, 20))->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        Invoice::factory()->count(2)->sequence(fn () => [
            'patient_id' => $allPatients->random()->id,
            'created_by' => $staff->id,
        ])->create();
        Invoice::factory()->void()->create([
            'patient_id' => $allPatients->random()->id,
            'created_by' => $staff->id,
        ]);
    }
}
