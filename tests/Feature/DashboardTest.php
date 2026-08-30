<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's job is to answer "what is happening today". It used to
 * show recall, outstanding balances, and stock — all true, none of it
 * about the day being worked — so these cover the today strip and the
 * pending-request tile that replaced that.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function appointmentToday(string $status, int $hour = 9): Appointment
    {
        return Appointment::factory()->create([
            'status' => $status,
            'start_time' => now()->setTime($hour, 0),
            'end_time' => now()->setTime($hour, 45),
        ]);
    }

    public function test_the_today_strip_counts_each_board_status(): void
    {
        $this->actingUser();

        $this->appointmentToday('scheduled', 9);
        $this->appointmentToday('scheduled', 10);
        $this->appointmentToday('checked_in', 11);
        $this->appointmentToday('in_treatment', 12);
        $this->appointmentToday('completed', 13);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('today.scheduled', 2)
            ->where('today.waiting', 1)
            ->where('today.in_treatment', 1)
            ->where('today.completed', 1)
        );
    }

    public function test_the_today_strip_ignores_other_days_and_non_board_statuses(): void
    {
        $this->actingUser();

        Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->addDay()->setTime(9, 0),
            'end_time' => now()->addDay()->setTime(9, 45),
        ]);
        $this->appointmentToday('cancelled', 9);
        $this->appointmentToday('no_show', 10);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('today.scheduled', 0)
            ->where('today.waiting', 0)
            ->where('today.completed', 0)
        );
    }

    public function test_the_next_appointment_is_the_soonest_still_ahead_today(): void
    {
        $this->actingUser();
        $this->travelTo(now()->setTime(10, 30));

        $this->appointmentToday('scheduled', 9);   // already passed
        $later = $this->appointmentToday('scheduled', 14);
        $soonest = $this->appointmentToday('scheduled', 11);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('today.next.id', $soonest->id)
            ->where('today.next.patient_name', $soonest->patient->full_name)
        );

        $this->assertNotSame($later->id, $soonest->id);
    }

    public function test_the_next_appointment_is_null_when_the_day_is_done(): void
    {
        $this->actingUser();
        $this->travelTo(now()->setTime(18, 0));
        $this->appointmentToday('completed', 9);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('today.next', null));
    }

    public function test_pending_requests_are_counted_with_the_oldest_wait(): void
    {
        $this->actingUser();

        Appointment::factory()->count(6)->create([
            'status' => 'requested',
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time_of_day' => 'morning',
        ]);
        Appointment::query()->where('status', 'requested')->first()
            ->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('requests.count', 6)
            ->where('requests.oldest_days', 4)
            // Capped so one busy day does not turn the dashboard into a list.
            ->has('requests.items', 4)
        );
    }

    public function test_pending_requests_are_empty_when_there_are_none(): void
    {
        $this->actingUser();

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('requests.count', 0)
            ->where('requests.oldest_days', null)
            ->has('requests.items', 0)
        );
    }

    public function test_recall_rows_carry_how_overdue_they_are(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create(['recall_interval_months' => 6]);
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'type' => 'cleaning',
            'status' => 'completed',
            'start_time' => now()->subMonths(7),
            'end_time' => now()->subMonths(7)->addMinutes(30),
        ]);

        $this->get(route('dashboard'))->assertInertia(function ($page) use ($patient) {
            $row = $page->toArray()['props']['dueForRecall'][0];

            $this->assertSame($patient->id, $row['id']);
            $this->assertGreaterThan(0, $row['overdue_days']);
        });
    }

    public function test_guest_cannot_view_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
