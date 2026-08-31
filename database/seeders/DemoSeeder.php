<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\StockMovement;
use App\Models\ToothCondition;
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
                    'paid_on' => min($issuedAt->clone()->addDays(rand(0, 20)), Carbon::now())->toDateString(),
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

        // --- Inventory fixtures: a stocked supply room so /inventory and
        //     its dashboard tile are populated on a fresh seed. Additive.
        $catalogue = [
            ['Nitrile Gloves (M)', 'ppe', 'box', 6],
            ['Composite Resin A2', 'consumable', 'syringe', 4],
            ['Lidocaine 2% Cartridges', 'medication', 'box', 3],
            ['Prophy Paste', 'consumable', 'tub', 5],
            ['Alginate Impression Material', 'lab_material', 'bag', 2],
            ['Assorted Diamond Burs', 'instrument', 'pack', 3],
            ['Cotton Rolls', 'consumable', 'box', 8],
            ['Surgical Face Masks', 'ppe', 'box', 6],
            ['Fluoride Varnish', 'medication', 'box', 2],
            ['Autoclave Pouches', 'consumable', 'box', 4],
            ['Disposable Saliva Ejectors', 'consumable', 'bag', 5],
            ['Patient Bibs', 'ppe', 'box', 4],
        ];
        $suppliers = ['Henry Schein', 'Patterson Dental', 'DentalKart', 'Benco Dental'];

        foreach ($catalogue as [$name, $category, $unit, $threshold]) {
            $item = InventoryItem::factory()->create([
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'reorder_threshold' => $threshold,
                'supplier' => $suppliers[array_rand($suppliers)],
                'created_by' => $staff->id,
            ]);

            StockMovement::factory()->create([
                'inventory_item_id' => $item->id,
                'type' => 'received',
                'quantity' => rand(3, 6) * 10,
                'unit_cost' => rand(20, 400),
                'occurred_on' => Carbon::now()->subDays(rand(30, 120))->toDateString(),
                'created_by' => $staff->id,
            ]);

            for ($n = 0; $n < rand(1, 3); $n++) {
                StockMovement::factory()->create([
                    'inventory_item_id' => $item->id,
                    'type' => 'consumed',
                    'quantity' => -rand(2, 8),
                    'unit_cost' => null,
                    'occurred_on' => Carbon::now()->subDays(rand(1, 25))->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        // Tune a few fixtures: two low on stock, one expiring, one archived.
        $inventory = InventoryItem::orderBy('id')->get();

        foreach ($inventory->take(2) as $item) {
            $current = (int) $item->movements()->sum('quantity');
            if ($current > 1) {
                StockMovement::factory()->create([
                    'inventory_item_id' => $item->id,
                    'type' => 'consumed',
                    'quantity' => -($current - 1),
                    'unit_cost' => null,
                    'occurred_on' => Carbon::now()->subDay()->toDateString(),
                    'created_by' => $staff->id,
                ]);
            }
        }

        $inventory->get(2)?->update(['expiry_date' => Carbon::now()->addDays(15)->toDateString()]);
        $inventory->get(3)?->update(['active' => false]);

        // --- Today's board: a clinic mid-morning, so /queue, the dashboard's
        //     today strip, and /workspace are populated on a fresh seed.
        //     Additive; nothing above changes.
        $boardPatients = $allPatients->shuffle()->take(5)->values();
        $boardStatuses = ['scheduled', 'scheduled', 'checked_in', 'in_treatment', 'completed'];

        foreach ($boardStatuses as $index => $status) {
            $start = Carbon::today()->setTime(9 + $index, [0, 30][$index % 2]);

            Appointment::factory()->create([
                'patient_id' => $boardPatients[$index % $boardPatients->count()]->id,
                'provider_id' => $providers->random()->id,
                'type' => $types[array_rand($types)],
                'status' => $status,
                'start_time' => $start,
                'end_time' => $start->clone()->addMinutes(45),
            ]);
        }

        // A pending public request, so the dashboard tile and the
        // confirm/decline flow on /appointments have something to act on.
        Appointment::factory()->create([
            'patient_id' => $allPatients->random()->id,
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => 'Dental Cleaning',
            'dentist_preference' => 'No preference',
            'preferred_date' => Carbon::now()->addDays(3)->toDateString(),
            'preferred_time_of_day' => 'morning',
            'notes' => 'Some sensitivity on the upper left with cold drinks.',
        ]);

        // --- Clinical fixtures. Without these a fresh seed cannot show the
        //     product's centrepiece: the records tab, the odontogram, and
        //     the prescriptions tab were all empty on /patients/{patient}.
        $chartedPatients = $allPatients->shuffle()->take(4)->values();

        foreach ($chartedPatients as $index => $patient) {
            $provider = $providers->random();
            $lastVisit = $patient->appointments()
                ->where('status', 'completed')
                ->orderByDesc('start_time')
                ->first();

            foreach ([
                ['consultation',
                    'Generalised mild plaque accumulation. Gingival margins inflamed on the lower anteriors.',
                    'Chronic marginal gingivitis.',
                    null,
                    'Advised interdental brushes. Recall in six months.'],
                ['procedure',
                    'Occlusal caries on tooth 30 confirmed radiographically.',
                    'Dental caries, tooth 30, occlusal surface.',
                    'Composite restoration, tooth 30. Local anaesthesia, one carpule articaine.',
                    'Patient tolerated the procedure well. No post-operative sensitivity reported.'],
                ['follow_up',
                    'Restoration on tooth 30 intact, margins sound.',
                    null,
                    null,
                    'Reviewed brushing technique.'],
            ] as [$type, $examination, $diagnosis, $procedure, $notes]) {
                $record = new DentalRecord([
                    'patient_id' => $patient->id,
                    'provider_id' => $provider->id,
                    'appointment_id' => $lastVisit?->id,
                    'type' => $type,
                    'examination' => $examination,
                    'diagnosis' => $diagnosis,
                    'procedure' => $procedure,
                    'notes' => $notes,
                ]);
                $record->created_by = $staff->id;
                $record->save();
            }

            // A plausible mouth: third molars out, a couple of restorations,
            // one lesion to treat, and — on one patient — a tooth charted
            // twice, so the chart's per-tooth history has something to show.
            $chart = [
                [1, 'missing', 'Extracted 2018.'],
                [16, 'missing', null],
                [17, 'missing', null],
                [32, 'missing', null],
                [3, 'filling', 'Amalgam, placed elsewhere.'],
                [14, 'crown', 'PFM crown, 2019.'],
                [19, 'root_canal', 'RCT complete, crown pending.'],
                [30, 'caries', 'Occlusal, to restore.'],
                [8, 'healthy', null],
            ];

            if ($index === 0) {
                $chart[] = [30, 'filling', 'Composite placed. Supersedes the caries entry above.'];
            }

            foreach ($chart as [$toothNumber, $condition, $toothNotes]) {
                $tooth = new ToothCondition([
                    'patient_id' => $patient->id,
                    'provider_id' => $provider->id,
                    'tooth_number' => $toothNumber,
                    'condition' => $condition,
                    'notes' => $toothNotes,
                ]);
                $tooth->created_by = $staff->id;
                $tooth->save();
            }

            foreach ([
                ['Amoxicillin', '500 mg', 'Three times daily', '7 days', '21 capsules',
                    'Take with food. Complete the full course.', 'active'],
                ['Ibuprofen', '400 mg', 'Every 6 hours as needed', '5 days', '20 tablets',
                    'Do not exceed 1600 mg in 24 hours.', 'active'],
                ['Chlorhexidine 0.12% rinse', '15 mL', 'Twice daily', '14 days', '1 bottle',
                    'Rinse for 30 seconds. Do not swallow.', 'discontinued'],
            ] as [$medication, $dosage, $frequency, $duration, $quantity, $instructions, $status]) {
                $rx = new Prescription([
                    'patient_id' => $patient->id,
                    'provider_id' => $provider->id,
                    'medication' => $medication,
                    'dosage' => $dosage,
                    'frequency' => $frequency,
                    'duration' => $duration,
                    'quantity' => $quantity,
                    'instructions' => $instructions,
                ]);
                $rx->created_by = $staff->id;
                $rx->status = $status;

                if ($status === 'discontinued') {
                    $rx->discontinued_at = Carbon::now()->subDays(3);
                    $rx->discontinued_reason = 'Course completed early; symptoms resolved.';
                }

                $rx->save();
            }
        }
    }
}
