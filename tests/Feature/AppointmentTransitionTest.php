<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Appointment::TRANSITIONS. Any status could previously become any other.
 *
 * The table is permissive about corrections and strict about nonsense, so
 * these cover both halves: every step a front-desk member can take by
 * mis-clicking has a way back, and the moves that would corrupt what
 * /reports counts are refused.
 */
class AppointmentTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** A complete, schedulable appointment in the given status. */
    private function appointment(string $status): Appointment
    {
        return Appointment::factory()->create([
            'status' => $status,
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 45),
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function legalProvider(): array
    {
        $cases = [];

        foreach (Appointment::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $cases["{$from} -> {$to}"] = [$from, $to];
            }
        }

        return $cases;
    }

    #[DataProvider('legalProvider')]
    public function test_every_declared_transition_is_accepted(string $from, string $to): void
    {
        $this->actingUser();
        $appointment = $this->appointment($from);

        $this->patch(route('appointments.update', $appointment), ['status' => $to])
            ->assertSessionHasNoErrors();

        $this->assertSame($to, $appointment->fresh()->status);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function illegalProvider(): array
    {
        $cases = [];

        foreach (Appointment::STATUSES as $from) {
            foreach (Appointment::STATUSES as $to) {
                if ($from === $to || Appointment::canTransition($from, $to)) {
                    continue;
                }

                $cases["{$from} -> {$to}"] = [$from, $to];
            }
        }

        return $cases;
    }

    #[DataProvider('illegalProvider')]
    public function test_every_undeclared_transition_is_refused(string $from, string $to): void
    {
        $this->actingUser();
        $appointment = $this->appointment($from);

        $this->patch(route('appointments.update', $appointment), ['status' => $to])
            ->assertSessionHasErrors('status');

        $this->assertSame($from, $appointment->fresh()->status);
    }

    public function test_staying_in_the_same_status_is_always_allowed(): void
    {
        $this->actingUser();

        foreach (Appointment::STATUSES as $status) {
            $appointment = $this->appointment($status);

            $this->patch(route('appointments.update', $appointment), ['status' => $status])
                ->assertSessionHasNoErrors();

            $this->assertSame($status, $appointment->fresh()->status);
        }
    }

    /**
     * The specific rules the table exists to encode, named so that
     * loosening one is a deliberate act rather than a table edit nobody
     * notices.
     */
    public function test_nothing_can_be_turned_back_into_a_request(): void
    {
        foreach (Appointment::STATUSES as $from) {
            if ($from === 'requested') {
                continue;
            }

            $this->assertFalse(
                Appointment::canTransition($from, 'requested'),
                "{$from} must not be able to become a request",
            );
        }
    }

    public function test_a_completed_visit_steps_back_only_to_in_treatment(): void
    {
        $this->assertSame(['in_treatment'], Appointment::TRANSITIONS['completed']);
    }

    public function test_a_declined_request_can_only_be_reconsidered_into_scheduled(): void
    {
        $this->assertSame(['scheduled'], Appointment::TRANSITIONS['declined']);
    }

    /** Every status in STATUSES has a row, or a move out of it would 500. */
    public function test_the_table_covers_every_status(): void
    {
        $this->assertSame(
            collect(Appointment::STATUSES)->sort()->values()->all(),
            collect(array_keys(Appointment::TRANSITIONS))->sort()->values()->all(),
        );

        foreach (Appointment::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertContains($to, Appointment::STATUSES, "{$from} points at unknown status {$to}");
            }
        }
    }

    /**
     * The calendar's status picker offers only what the server will
     * accept — otherwise a receptionist picks an option and is told no.
     */
    public function test_the_calendar_receives_the_transition_table(): void
    {
        $this->actingUser();

        $this->get(route('appointments.index'))->assertInertia(fn ($page) => $page
            ->component('Appointments/Index')
            ->where('transitions', Appointment::TRANSITIONS)
        );
    }

    /**
     * The queue board's four buttons are the transitions staff use most.
     * If the table ever stops allowing one, the board silently breaks.
     */
    public function test_the_queue_board_actions_are_all_legal(): void
    {
        foreach ([
            ['scheduled', 'checked_in'],
            ['scheduled', 'no_show'],
            ['checked_in', 'in_treatment'],
            ['in_treatment', 'completed'],
        ] as [$from, $to]) {
            $this->assertTrue(
                Appointment::canTransition($from, $to),
                "the queue board's {$from} -> {$to} button is not a legal transition",
            );
        }
    }
}
