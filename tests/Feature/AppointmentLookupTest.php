<?php

namespace Tests\Feature;

use App\Mail\AppointmentLookupLink;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AppointmentLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_form_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('appointments.lookup.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Public/AppointmentLookup'));
    }

    public function test_requesting_a_link_for_a_known_email_sends_it(): void
    {
        Mail::fake();
        $patient = Patient::factory()->create(['email' => 'angela@example.com']);

        $response = $this->post(route('appointments.lookup.send'), [
            'email' => 'angela@example.com',
        ]);

        $response->assertRedirect();
        // Queued, not sent synchronously — a synchronous send() would make the
        // "found" branch measurably slower than the "not found" branch (Blade
        // render + log-driver disk write happening inside the request), which
        // would defeat the identical-response anti-enumeration property below.
        Mail::assertQueued(AppointmentLookupLink::class, fn ($mail) => $mail->hasTo('angela@example.com')
            && $mail->patient->is($patient));
    }

    public function test_requesting_a_link_for_an_unknown_email_sends_nothing_but_responds_identically(): void
    {
        Mail::fake();

        $knownPatient = Patient::factory()->create(['email' => 'angela@example.com']);
        $knownResponse = $this->post(route('appointments.lookup.send'), [
            'email' => 'angela@example.com',
        ]);

        $unknownResponse = $this->post(route('appointments.lookup.send'), [
            'email' => 'nobody@example.com',
        ]);

        Mail::assertQueued(AppointmentLookupLink::class, 1);
        $unknownResponse->assertRedirect($knownResponse->headers->get('Location'));
        $this->assertSame(
            $knownResponse->getSession()->get('status'),
            $unknownResponse->getSession()->get('status'),
        );
    }

    public function test_email_matching_is_case_insensitive(): void
    {
        Mail::fake();
        Patient::factory()->create(['email' => 'angela@example.com']);

        $this->post(route('appointments.lookup.send'), [
            'email' => 'ANGELA@Example.COM',
        ]);

        Mail::assertQueued(AppointmentLookupLink::class, 1);
    }

    public function test_email_is_required(): void
    {
        Mail::fake();

        $response = $this->post(route('appointments.lookup.send'), []);

        $response->assertSessionHasErrors('email');
        Mail::assertNothingOutgoing();
    }

    public function test_email_must_be_a_valid_format(): void
    {
        Mail::fake();

        $response = $this->post(route('appointments.lookup.send'), [
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email');
        Mail::assertNothingOutgoing();
    }

    public function test_repeated_requests_for_the_same_email_are_rate_limited(): void
    {
        Mail::fake();
        Patient::factory()->create(['email' => 'angela@example.com']);

        foreach (range(1, 4) as $attempt) {
            $response = $this->post(route('appointments.lookup.send'), [
                'email' => 'angela@example.com',
            ]);

            // Still the same generic response even once the limit is hit —
            // an attacker must not be able to tell "rate limited" apart from
            // "no such patient" or "link sent".
            $response->assertRedirect();
        }

        Mail::assertQueuedCount(3);
    }

    public function test_rate_limiting_is_scoped_per_email(): void
    {
        Mail::fake();
        Patient::factory()->create(['email' => 'angela@example.com']);
        Patient::factory()->create(['email' => 'rico@example.com']);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('appointments.lookup.send'), ['email' => 'angela@example.com']);
        }
        $this->post(route('appointments.lookup.send'), ['email' => 'rico@example.com']);

        Mail::assertQueuedCount(4);
    }

    public function test_a_valid_signed_link_shows_the_patients_appointments(): void
    {
        $patient = Patient::factory()->create();
        $provider = Provider::factory()->create(['name' => 'Dr. Elena Santos']);
        $scheduled = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => 'cleaning',
            'status' => 'scheduled',
            'start_time' => '2026-09-02 09:00:00',
            'end_time' => '2026-09-02 09:30:00',
        ]);
        $requested = Appointment::factory()->requested()->create([
            'patient_id' => $patient->id,
            'service_interest' => 'Root Canal Treatment',
            'preferred_date' => '2026-09-10',
            'preferred_time_of_day' => 'afternoon',
        ]);

        $url = URL::temporarySignedRoute(
            'appointments.lookup.show',
            now()->addMinutes(30),
            ['patient' => $patient->id],
        );

        $response = $this->get($url);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/AppointmentLookupResults')
            ->has('appointments', 2)
            ->where('appointments.0.id', $requested->id)
            ->where('appointments.0.status', 'requested')
            ->where('appointments.0.service', 'Root Canal Treatment')
            ->where('appointments.0.preferred_date', '2026-09-10')
            ->where('appointments.0.preferred_time_of_day', 'afternoon')
            ->where('appointments.1.id', $scheduled->id)
            ->where('appointments.1.status', 'scheduled')
            ->where('appointments.1.provider', 'Dr. Elena Santos')
        );
    }

    public function test_lookup_only_shows_that_patients_own_appointments(): void
    {
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();
        Appointment::factory()->requested()->create(['patient_id' => $patient->id]);
        Appointment::factory()->requested()->create(['patient_id' => $otherPatient->id]);

        $url = URL::temporarySignedRoute(
            'appointments.lookup.show',
            now()->addMinutes(30),
            ['patient' => $patient->id],
        );

        $response = $this->get($url);

        $response->assertInertia(fn ($page) => $page->has('appointments', 1));
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $patient = Patient::factory()->create();

        $url = URL::temporarySignedRoute(
            'appointments.lookup.show',
            now()->subMinute(),
            ['patient' => $patient->id],
        );

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();

        $url = URL::temporarySignedRoute(
            'appointments.lookup.show',
            now()->addMinutes(30),
            ['patient' => $patient->id],
        );
        $tamperedUrl = str_replace('/my-appointments/'.$patient->id, '/my-appointments/'.$otherPatient->id, $url);

        $response = $this->get($tamperedUrl);

        $response->assertForbidden();
    }
}
