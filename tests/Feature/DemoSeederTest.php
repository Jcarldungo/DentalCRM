<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DentalRecord;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ToothCondition;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seed is what a fresh checkout — and any demonstration of the
 * product — actually shows. It previously created patients, appointments,
 * billing, and inventory but no clinical content at all, so the records
 * tab, the odontogram, and the prescriptions tab were empty on every
 * patient, and nothing was scheduled for today, so /queue and the
 * dashboard's today strip were blank.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seed_populates_every_surface(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, Patient::count(), 'patients');
        $this->assertGreaterThan(0, Invoice::count(), 'invoices');
        $this->assertGreaterThan(0, InventoryItem::count(), 'inventory');
        $this->assertGreaterThan(0, DentalRecord::count(), 'dental records');
        $this->assertGreaterThan(0, ToothCondition::count(), 'tooth conditions');
        $this->assertGreaterThan(0, Prescription::count(), 'prescriptions');

        $this->assertGreaterThan(
            0,
            Appointment::whereDate('start_time', now()->toDateString())
                ->whereIn('status', Appointment::BOARD_STATUSES)
                ->count(),
            "the queue board has nothing on it today",
        );

        $this->assertGreaterThan(
            0,
            Appointment::where('status', 'requested')->count(),
            'no pending request to confirm or decline',
        );
    }

    public function test_the_seeded_chart_has_a_tooth_with_more_than_one_entry(): void
    {
        $this->seed(DemoSeeder::class);

        $repeated = ToothCondition::selectRaw('patient_id, tooth_number, count(*) as entries')
            ->groupBy('patient_id', 'tooth_number')
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertTrue($repeated, 'no tooth has a history to show');
    }

    public function test_the_seeded_prescriptions_cover_both_states(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, Prescription::where('status', 'active')->count());
        $this->assertGreaterThan(0, Prescription::where('status', 'discontinued')->count());
    }

    /**
     * Every staff page renders against the seeded data. This is the cheap
     * catch for a projection that assumes a relation is always present.
     */
    public function test_every_staff_page_renders_against_the_seeded_data(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(\App\Models\User::factory()->create());

        foreach ([
            'dashboard', 'patients.index', 'queue.index', 'appointments.index',
            'workspace.index', 'invoices.index', 'reports.index',
            'inventory.index', 'inquiries.index', 'providers.index',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }

        $this->get(route('patients.show', Patient::first()))->assertOk();
        $this->get(route('invoices.show', Invoice::first()))->assertOk();
        $this->get(route('inventory.show', InventoryItem::first()))->assertOk();
    }
}
