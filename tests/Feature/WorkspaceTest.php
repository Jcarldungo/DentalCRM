<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_the_workspace(): void
    {
        $this->get(route('workspace.index'))->assertRedirect(route('login'));
    }

    public function test_it_renders_the_workspace_with_defaults(): void
    {
        $this->actingUser();
        Provider::factory()->create(['name' => 'Dr. Alvarez', 'active' => true]);

        $response = $this->get(route('workspace.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Workspace/Index')
            ->where('date', now()->toDateString())
            ->where('selectedProviderId', null)
            ->has('providers', 1)
            ->has('appointments', 0)
        );
    }

    public function test_it_lists_the_target_days_appointments_ordered_by_start_time(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $later = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->addHours(2), 'end_time' => $day->clone()->addHours(2)->addMinutes(30)]);
        $sooner = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->addDay(), 'end_time' => $day->clone()->addDay()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $day->clone()->subDay(), 'end_time' => $day->clone()->subDay()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('appointments', 2)
            ->where('appointments.0.id', $sooner->id)
            ->where('appointments.1.id', $later->id)
        );
    }

    public function test_the_date_param_selects_that_day(): void
    {
        $this->actingUser();
        $target = now()->addDays(3)->startOfDay()->addHours(10);
        $onTarget = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $target->clone(), 'end_time' => $target->clone()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => now()->startOfDay()->addHours(10), 'end_time' => now()->startOfDay()->addHours(10)->addMinutes(30)]);

        $response = $this->get(route('workspace.index', ['date' => $target->toDateString()]));

        $response->assertInertia(fn ($page) => $page
            ->where('date', $target->toDateString())
            ->has('appointments', 1)
            ->where('appointments.0.id', $onTarget->id)
        );
    }

    public function test_the_provider_id_param_filters_to_that_provider(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $mine = Provider::factory()->create(['active' => true]);
        $theirs = Provider::factory()->create(['active' => true]);
        $a = Appointment::factory()->create(['provider_id' => $mine->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['provider_id' => $theirs->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index', ['provider_id' => $mine->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedProviderId', $mine->id)
            ->has('appointments', 1)
            ->where('appointments.0.id', $a->id)
        );
    }

    public function test_it_excludes_requested_cancelled_declined_and_no_show(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        foreach (['cancelled', 'declined', 'no_show'] as $i => $status) {
            Appointment::factory()->create(['status' => $status, 'start_time' => $day->clone()->addHours($i), 'end_time' => $day->clone()->addHours($i)->addMinutes(30)]);
        }
        Appointment::factory()->requested()->create(['preferred_date' => $day->toDateString()]);
        $shown = Appointment::factory()->create(['status' => 'completed', 'start_time' => $day->clone()->addHours(5), 'end_time' => $day->clone()->addHours(5)->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('appointments', 1)
            ->where('appointments.0.id', $shown->id)
        );
    }

    public function test_badge_counts_are_per_patient_and_only_count_open_active_rows(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);

        $busy = Patient::factory()->create();
        TreatmentPlanItem::factory()->count(2)->create(['patient_id' => $busy->id, 'status' => 'planned']);
        TreatmentPlanItem::factory()->create(['patient_id' => $busy->id, 'status' => 'completed']);
        Prescription::factory()->create(['patient_id' => $busy->id, 'status' => 'active']);
        Prescription::factory()->discontinued()->create(['patient_id' => $busy->id]);
        Appointment::factory()->create(['patient_id' => $busy->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);

        $fresh = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $fresh->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('appointments.0.patient_id', $busy->id)
            ->where('appointments.0.open_treatment_count', 2)
            ->where('appointments.0.active_prescription_count', 1)
            ->where('appointments.1.patient_id', $fresh->id)
            ->where('appointments.1.open_treatment_count', 0)
            ->where('appointments.1.active_prescription_count', 0)
        );
    }

    public function test_an_inactive_provider_is_absent_from_the_list_but_its_appointment_still_shows(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $inactive = Provider::factory()->create(['active' => false]);
        $appt = Appointment::factory()->create(['provider_id' => $inactive->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('providers', 0)
            ->has('appointments', 1)
            ->where('appointments.0.id', $appt->id)
        );
    }

    public function test_patient_age_is_an_integer_or_null(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $withDob = Patient::factory()->create(['date_of_birth' => now()->subYears(30)->subMonths(2)->toDateString()]);
        $noDob = Patient::factory()->create(['date_of_birth' => null]);
        Appointment::factory()->create(['patient_id' => $withDob->id, 'status' => 'scheduled', 'start_time' => $day->clone(), 'end_time' => $day->clone()->addMinutes(30)]);
        Appointment::factory()->create(['patient_id' => $noDob->id, 'status' => 'scheduled', 'start_time' => $day->clone()->addHour(), 'end_time' => $day->clone()->addHour()->addMinutes(30)]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('appointments.0.patient_age', 30)
            ->where('appointments.1.patient_age', null)
        );
    }

    public function test_a_nonexistent_provider_id_is_rejected(): void
    {
        $this->actingUser();
        $this->get(route('workspace.index', ['provider_id' => 999999]))->assertSessionHasErrors('provider_id');
    }

    public function test_an_unparseable_date_is_rejected(): void
    {
        $this->actingUser();
        $this->get(route('workspace.index', ['date' => 'not-a-date']))->assertSessionHasErrors('date');
    }

    public function test_each_appointment_row_carries_the_full_contract(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => $day->clone(),
            'end_time' => $day->clone()->addMinutes(30),
        ]);

        $response = $this->get(route('workspace.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('appointments.0', fn ($row) => $row->hasAll([
                'id', 'patient_id', 'patient_name', 'provider_name', 'patient_age',
                'type', 'status', 'start_time', 'end_time', 'notes',
                'open_treatment_count', 'active_prescription_count',
            ]))
        );
    }

    public function test_selected_inactive_provider_is_echoed_and_named_on_rows(): void
    {
        $this->actingUser();
        $day = now()->startOfDay()->addHours(9);
        $inactive = Provider::factory()->create(['name' => 'Dr. Retired', 'active' => false]);
        $appt = Appointment::factory()->create([
            'provider_id' => $inactive->id,
            'status' => 'scheduled',
            'start_time' => $day->clone(),
            'end_time' => $day->clone()->addMinutes(30),
        ]);

        $response = $this->get(route('workspace.index', ['provider_id' => $inactive->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('selectedProviderId', $inactive->id)
            ->has('providers', 0)
            ->where('appointments.0.id', $appt->id)
            ->where('appointments.0.provider_name', 'Dr. Retired')
        );
    }
}
