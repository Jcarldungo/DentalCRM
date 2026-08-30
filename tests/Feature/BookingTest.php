<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Monday, comfortably in the future — the clinic is open, and it passes
     * the after_or_equal:today rule regardless of when the suite runs.
     */
    private function openDate(): string
    {
        return now()->addWeek()->next(Carbon::MONDAY)->toDateString();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Angela Reyes',
            'email' => 'angela@example.com',
            'phone' => '09171234567',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_date' => $this->openDate(),
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
        ], $overrides);
    }

    public function test_guest_can_submit_an_appointment_request(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('appointments', [
            'status' => 'requested',
            'service_interest' => 'Teeth Whitening',
            'dentist_preference' => 'Dr. Elena Santos',
            'preferred_time_of_day' => 'morning',
            'notes' => 'Upper-right tooth pain.',
            'start_time' => null,
            'end_time' => null,
            'provider_id' => null,
            'type' => null,
        ]);
        $this->assertSame($this->openDate(), Appointment::first()->preferred_date->toDateString());
    }

    public function test_dentist_preference_and_notes_are_optional(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'dentist_preference' => null,
            'notes' => null,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', [
            'status' => 'requested',
            'dentist_preference' => null,
            'notes' => null,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $response = $this->post(route('bookings.store'), []);

        $response->assertSessionHasErrors([
            'name',
            'email',
            'phone',
            'service_interest',
            'preferred_date',
            'preferred_time_of_day',
        ]);
        $this->assertSame(0, Appointment::count());
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertSessionHasErrors('email');
    }

    public function test_preferred_date_must_be_a_real_date(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => 'next tuesday-ish',
        ]));

        $response->assertSessionHasErrors('preferred_date');
    }

    public function test_preferred_date_cannot_be_in_the_past(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => now()->subDay()->toDateString(),
        ]));

        $response->assertSessionHasErrors('preferred_date');
    }

    public function test_preferred_date_cannot_be_a_day_the_clinic_is_closed(): void
    {
        $sunday = now()->addWeek()->next(Carbon::SUNDAY)->toDateString();

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $sunday,
        ]));

        $response->assertSessionHasErrors('preferred_date');
        $this->assertSame(0, Appointment::count());
    }

    public function test_preferred_time_of_day_must_be_morning_or_afternoon(): void
    {
        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_time_of_day' => 'midnight',
        ]));

        $response->assertSessionHasErrors('preferred_time_of_day');
    }

    public function test_an_existing_patient_with_the_same_email_is_reused(): void
    {
        $existing = Patient::factory()->create(['email' => 'angela@example.com']);

        $this->post(route('bookings.store'), $this->validPayload());

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Appointment::first()->patient_id);
    }

    public function test_patient_email_matching_is_case_insensitive(): void
    {
        $existing = Patient::factory()->create(['email' => 'angela@example.com']);

        $this->post(route('bookings.store'), $this->validPayload([
            'email' => 'ANGELA@Example.COM',
        ]));

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Appointment::first()->patient_id);
    }

    public function test_a_new_patient_is_created_when_no_email_matches(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'name' => 'Rico Dela Cruz',
            'email' => 'rico@example.com',
        ]));

        $this->assertSame(1, Patient::count());
        $this->assertDatabaseHas('patients', [
            'first_name' => 'Rico',
            'last_name' => 'Dela Cruz',
            'email' => 'rico@example.com',
            'phone' => '09171234567',
        ]);
    }

    public function test_a_single_word_name_becomes_the_first_name(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'name' => 'Madonna',
            'email' => 'madonna@example.com',
        ]));

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Madonna',
            'last_name' => '',
        ]);
    }

    public function test_a_request_does_not_appear_on_the_staff_calendar_feed(): void
    {
        $this->post(route('bookings.store'), $this->validPayload());

        $this->actingAs(User::factory()->create());

        $response = $this->getJson(route('appointments.events', [
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->addMonths(3)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_a_request_is_rejected_once_the_slot_is_at_capacity(): void
    {
        config(['clinic.max_requests_per_slot' => 2]);
        $date = $this->openDate();

        Appointment::factory()->count(2)->requested()->create([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasErrors('preferred_time_of_day');
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_request_is_accepted_when_the_slot_is_under_capacity(): void
    {
        config(['clinic.max_requests_per_slot' => 2]);
        $date = $this->openDate();

        Appointment::factory()->requested()->create([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_scheduled_appointments_count_toward_slot_capacity(): void
    {
        config(['clinic.max_requests_per_slot' => 1]);
        $date = $this->openDate();

        Appointment::factory()->create([
            'start_time' => Carbon::parse($date)->setTime(9, 0),
            'end_time' => Carbon::parse($date)->setTime(9, 30),
            'status' => 'scheduled',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasErrors('preferred_time_of_day');
        $this->assertSame(1, Appointment::count());
    }

    public function test_declined_requests_do_not_count_toward_slot_capacity(): void
    {
        config(['clinic.max_requests_per_slot' => 1]);
        $date = $this->openDate();

        Appointment::factory()->requested()->create([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
            'status' => 'declined',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_slot_capacity_does_not_block_a_different_time_of_day(): void
    {
        config(['clinic.max_requests_per_slot' => 1]);
        $date = $this->openDate();

        Appointment::factory()->requested()->create([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'afternoon',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_confirming_a_request_into_a_different_time_of_day_frees_its_original_slot(): void
    {
        config(['clinic.max_requests_per_slot' => 1]);
        $date = $this->openDate();

        // Guest originally requested morning; staff only had an afternoon
        // opening and confirmed it there. The request's preferred_* fields
        // are never cleared on confirm, so the stale morning preference must
        // not still count toward the morning slot.
        Appointment::factory()->requested()->create([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
            'status' => 'scheduled',
            'provider_id' => Provider::factory(),
            'start_time' => Carbon::parse($date)->setTime(14, 0),
            'end_time' => Carbon::parse($date)->setTime(14, 30),
            'type' => 'checkup',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_an_appointment_starting_exactly_at_the_afternoon_boundary_does_not_also_fill_the_morning_slot(): void
    {
        config(['clinic.max_requests_per_slot' => 1]);
        $date = $this->openDate();

        Appointment::factory()->create([
            'start_time' => Carbon::parse($date)->setTime(12, 0),
            'end_time' => Carbon::parse($date)->setTime(12, 30),
            'status' => 'scheduled',
        ]);

        $response = $this->post(route('bookings.store'), $this->validPayload([
            'preferred_date' => $date,
            'preferred_time_of_day' => 'morning',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::count());
    }

    public function test_service_interest_must_be_a_bookable_service(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'service_interest' => 'URGENT - your account is overdue, call 0917-555-0100',
        ]))->assertSessionHasErrors('service_interest');

        $this->assertSame(0, Appointment::count());
    }

    public function test_dentist_preference_must_be_a_bookable_dentist(): void
    {
        $this->post(route('bookings.store'), $this->validPayload([
            'dentist_preference' => 'Dr. Not A Real Person',
        ]))->assertSessionHasErrors('dentist_preference');

        $this->assertSame(0, Appointment::count());
    }

    /**
     * Both fields are rendered back to the patient on their own signed
     * lookup page, so free text there is attacker-authored content inside
     * the clinic's branded UI. The form and the rule must offer the same
     * set, which is why the lists reach the page as props.
     */
    public function test_the_bookable_lists_are_the_ones_the_form_offers(): void
    {
        $this->get(route('book'))->assertInertia(fn ($page) => $page
            ->component('Public/Book')
            ->where('bookableServices', config('clinic.bookable_services'))
            ->where('bookableDentists', config('clinic.bookable_dentists'))
        );
    }

    public function test_a_preferred_date_beyond_the_booking_horizon_is_rejected(): void
    {
        $tooFar = now()->addDays((int) config('clinic.max_booking_days_ahead') + 1)
            ->next(Carbon::MONDAY)->toDateString();

        $this->post(route('bookings.store'), $this->validPayload(['preferred_date' => $tooFar]))
            ->assertSessionHasErrors('preferred_date');

        $this->post(route('bookings.store'), $this->validPayload(['preferred_date' => '9999-12-31']))
            ->assertSessionHasErrors('preferred_date');

        $this->assertSame(0, Appointment::count());
    }

    /**
     * max_requests_per_slot is 6, so a 6/minute limit let one address
     * saturate a whole slot every minute and deny the clinic real bookings.
     */
    public function test_bookings_are_limited_to_three_per_hour_per_address(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('bookings.store'), $this->validPayload([
                'email' => "guest{$i}@example.com",
            ]))->assertRedirect();
        }

        $this->post(route('bookings.store'), $this->validPayload(['email' => 'guest4@example.com']))
            ->assertStatus(429);

        $this->assertSame(3, Appointment::count());
    }

    public function test_two_patients_cannot_share_an_email(): void
    {
        Patient::factory()->create(['email' => 'shared@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);
        Patient::factory()->create(['email' => 'shared@example.com']);
    }

    public function test_many_patients_may_have_no_email(): void
    {
        Patient::factory()->count(3)->create(['email' => null]);

        $this->assertSame(3, Patient::whereNull('email')->count());
    }
}
