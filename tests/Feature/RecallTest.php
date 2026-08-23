<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallTest extends TestCase
{
    use RefreshDatabase;

    protected function completedCleaning(Patient $patient, string $when): void
    {
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'type' => 'cleaning',
            'status' => 'completed',
            'start_time' => $when,
            'end_time' => Carbon::parse($when)->addMinutes(30),
        ]);
    }

    public function test_patient_with_no_completed_cleaning_is_excluded(): void
    {
        Patient::factory()->create(['first_name' => 'A']);

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(0, $due);
    }

    public function test_patient_inside_default_interval_is_excluded(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'B', 'recall_interval_months' => null]);
        $this->completedCleaning($patient, '2026-01-15'); // 4.5 months before as-of; due 2026-07-15

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(0, $due);
    }

    public function test_patient_past_default_interval_is_included(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'C', 'recall_interval_months' => null]);
        $this->completedCleaning($patient, '2025-11-01'); // due 2026-05-01, before as-of

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(1, $due);
        $this->assertSame($patient->id, $due->first()->id);
    }

    public function test_per_patient_recall_interval_override_is_respected(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'D', 'recall_interval_months' => 2]);
        $this->completedCleaning($patient, '2026-02-01'); // due 2026-04-01 with a 2-month interval

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(1, $due);
        $this->assertSame($patient->id, $due->first()->id);
    }

    public function test_results_are_sorted_most_overdue_first(): void
    {
        $patientC = Patient::factory()->create(['first_name' => 'C', 'recall_interval_months' => null]);
        $this->completedCleaning($patientC, '2025-11-01'); // due 2026-05-01

        $patientD = Patient::factory()->create(['first_name' => 'D', 'recall_interval_months' => 2]);
        $this->completedCleaning($patientD, '2026-02-01'); // due 2026-04-01

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(2, $due);
        $this->assertSame($patientD->id, $due->get(0)->id); // due 2026-04-01, earlier
        $this->assertSame($patientC->id, $due->get(1)->id); // due 2026-05-01, later
    }

    public function test_only_the_most_recent_completed_cleaning_counts(): void
    {
        $patient = Patient::factory()->create(['recall_interval_months' => null]);
        $this->completedCleaning($patient, '2025-01-01'); // old cleaning, would be overdue alone
        $this->completedCleaning($patient, '2026-05-20'); // recent cleaning, due 2026-11-20

        $due = Patient::dueForRecall(Carbon::parse('2026-06-01'));

        $this->assertCount(0, $due);
    }
}
