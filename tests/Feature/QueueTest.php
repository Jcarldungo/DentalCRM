<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_a_scheduled_appointment_can_be_checked_in_via_the_existing_update_endpoint(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'checked_in',
        ]);

        $response->assertRedirect();
        $this->assertSame('checked_in', $appointment->fresh()->status);
    }

    public function test_a_checked_in_appointment_can_start_treatment(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'checked_in']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'in_treatment',
        ]);

        $response->assertRedirect();
        $this->assertSame('in_treatment', $appointment->fresh()->status);
    }

    public function test_an_in_treatment_appointment_can_be_completed(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'in_treatment']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_a_scheduled_appointment_can_be_marked_no_show(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $response = $this->patch(route('appointments.update', $appointment), [
            'status' => 'no_show',
        ]);

        $response->assertRedirect();
        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_checked_in_and_in_treatment_still_occupy_their_provider_slot(): void
    {
        $this->actingUser();
        $provider = \App\Models\Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:00:00',
            'end_time' => '2026-09-01 10:00:00',
            'status' => 'checked_in',
        ]);

        $response = $this->post(route('appointments.store'), [
            'patient_id' => \App\Models\Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'start_time' => '2026-09-01 09:30:00',
            'end_time' => '2026-09-01 10:30:00',
            'type' => 'cleaning',
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Appointment::count());
    }

    public function test_guest_cannot_view_the_queue(): void
    {
        $response = $this->get(route('queue.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_index_scopes_each_column_to_todays_appointments_by_status(): void
    {
        $this->actingUser();
        $today = now()->setTime(9, 0);

        $scheduled = Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $today->clone(), 'end_time' => $today->clone()->addMinutes(30)]);
        $waiting = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->addHour(), 'end_time' => $today->clone()->addHour()->addMinutes(30)]);
        $serving = Appointment::factory()->create(['status' => 'in_treatment', 'start_time' => $today->clone()->addHours(2), 'end_time' => $today->clone()->addHours(2)->addMinutes(30)]);
        $completed = Appointment::factory()->create(['status' => 'completed', 'start_time' => $today->clone()->addHours(3), 'end_time' => $today->clone()->addHours(3)->addMinutes(30)]);

        // Outside today — must not appear in any column.
        Appointment::factory()->create(['status' => 'scheduled', 'start_time' => $today->clone()->addDay(), 'end_time' => $today->clone()->addDay()->addMinutes(30)]);
        Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->subDay(), 'end_time' => $today->clone()->subDay()->addMinutes(30)]);

        $response = $this->get(route('queue.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Queue/Index')
            ->has('todaysSchedule', 1)
            ->where('todaysSchedule.0.id', $scheduled->id)
            ->has('waiting', 1)
            ->where('waiting.0.id', $waiting->id)
            ->has('nowServing', 1)
            ->where('nowServing.0.id', $serving->id)
            ->has('completed', 1)
            ->where('completed.0.id', $completed->id)
        );
    }

    public function test_index_orders_each_column_by_start_time_ascending(): void
    {
        $this->actingUser();
        $today = now()->setTime(9, 0);

        $later = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone()->addHours(2), 'end_time' => $today->clone()->addHours(2)->addMinutes(30)]);
        $sooner = Appointment::factory()->create(['status' => 'checked_in', 'start_time' => $today->clone(), 'end_time' => $today->clone()->addMinutes(30)]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('waiting.0.id', $sooner->id)
            ->where('waiting.1.id', $later->id)
        );
    }

    public function test_index_card_includes_patient_provider_and_type(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Cruz']);
        $provider = \App\Models\Provider::factory()->create(['name' => 'Dr. Santos']);
        Appointment::factory()->create([
            'status' => 'scheduled',
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'cleaning',
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 30),
        ]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('todaysSchedule.0.patient_name', 'Maria Cruz')
            ->where('todaysSchedule.0.provider_name', 'Dr. Santos')
            ->where('todaysSchedule.0.type', 'cleaning')
        );
    }

    public function test_guest_cannot_add_a_walk_in(): void
    {
        $patient = \App\Models\Patient::factory()->create();
        $provider = \App\Models\Provider::factory()->create();

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_a_walk_in_is_created_checked_in_with_a_thirty_minute_block(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create();
        $provider = \App\Models\Provider::factory()->create();

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Appointment::count());
        $appointment = Appointment::first();
        $this->assertSame('checked_in', $appointment->status);
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame($provider->id, $appointment->provider_id);
        $this->assertSame('checkup', $appointment->type);
        $this->assertEqualsWithDelta(now()->timestamp, $appointment->start_time->timestamp, 5);
        $this->assertSame(
            $appointment->start_time->clone()->addMinutes(30)->timestamp,
            $appointment->end_time->timestamp
        );
    }

    public function test_a_walk_in_appears_in_the_waiting_column(): void
    {
        $this->actingUser();
        $patient = \App\Models\Patient::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $provider = \App\Models\Provider::factory()->create();

        $this->post(route('queue.walkins.store'), [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response = $this->get(route('queue.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('waiting', 1)
            ->where('waiting.0.patient_name', 'Juan Dela Cruz')
        );
    }

    public function test_a_walk_in_conflicting_with_the_providers_schedule_is_rejected(): void
    {
        $this->actingUser();
        $provider = \App\Models\Provider::factory()->create();
        Appointment::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'scheduled',
            'start_time' => now(),
            'end_time' => now()->addMinutes(30),
        ]);

        $response = $this->post(route('queue.walkins.store'), [
            'patient_id' => \App\Models\Patient::factory()->create()->id,
            'provider_id' => $provider->id,
            'type' => 'checkup',
        ]);

        $response->assertSessionHasErrors('provider_id');
        $this->assertSame(1, Appointment::count());
    }

    /**
     * The crash path: a guest request has a null provider_id and end_time,
     * and PATCHing it onto the board used to be accepted, after which
     * /queue dereferenced provider->name and 500'd for every staff member.
     *
     * Two guards now stand in the way, and this covers the outer one:
     * Appointment::TRANSITIONS does not allow a request to jump straight
     * onto the board at all. `scheduled` is a legal move, so it is covered
     * separately below by the schedule-completeness guard.
     *
     * @return array<string, array{0: string}>
     */
    public static function boardStatusProvider(): array
    {
        return [
            'checked_in' => ['checked_in'],
            'in_treatment' => ['in_treatment'],
            'completed' => ['completed'],
        ];
    }

    #[DataProvider('boardStatusProvider')]
    public function test_a_request_cannot_jump_straight_onto_the_board(string $status): void
    {
        $this->actingUser();
        $request = $this->guestRequest();

        $this->patch(route('appointments.update', $request), ['status' => $status])
            ->assertSessionHasErrors('status');

        $this->assertSame('requested', $request->fresh()->status);
    }

    /**
     * requested -> scheduled IS a legal transition, so the second guard has
     * to catch it: an appointment cannot reach the board without a
     * provider, a start, an end, and a type.
     */
    public function test_confirming_a_request_requires_a_complete_schedule(): void
    {
        $this->actingUser();
        $request = $this->guestRequest();

        $this->patch(route('appointments.update', $request), ['status' => 'scheduled'])
            ->assertSessionHasErrors(['provider_id', 'start_time', 'end_time', 'type']);

        $this->assertSame('requested', $request->fresh()->status);
    }

    public function test_a_start_time_alone_does_not_confirm_a_request(): void
    {
        $this->actingUser();
        $request = $this->guestRequest();

        $this->patch(route('appointments.update', $request), [
            'status' => 'scheduled',
            'start_time' => now()->setTime(9, 0)->toDateTimeString(),
            'end_time' => now()->setTime(9, 30)->toDateTimeString(),
        ])->assertSessionHasErrors(['provider_id', 'type']);

        $this->assertSame('requested', $request->fresh()->status);
    }

    private function guestRequest(): Appointment
    {
        return Appointment::factory()->create([
            'status' => 'requested',
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
        ]);
    }

    /**
     * Defence in depth for rows written before the guard above existed.
     */
    public function test_the_queue_renders_with_a_malformed_pre_existing_row(): void
    {
        $this->actingUser();
        $appointment = Appointment::factory()->create([
            'status' => 'checked_in',
            'start_time' => now()->setTime(9, 0),
        ]);
        // Bypass the model so no application guard applies — this is the
        // shape a row written before the fix would have.
        \Illuminate\Support\Facades\DB::table('appointments')
            ->where('id', $appointment->id)
            ->update(['provider_id' => null, 'end_time' => null, 'type' => null]);

        $this->get(route('queue.index'))->assertOk();
        $this->get(route('workspace.index'))->assertOk();
    }
}
