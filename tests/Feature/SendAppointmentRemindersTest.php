<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendAppointmentRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reminder_is_sent_for_a_scheduled_appointment_tomorrow(): void
    {
        Mail::fake();
        $patient = Patient::factory()->create(['email' => 'angela@example.com']);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'scheduled',
            'start_time' => now()->addDay()->setTime(9, 0),
            'end_time' => now()->addDay()->setTime(9, 30),
        ]);

        $this->artisan('appointments:send-reminders')->assertExitCode(0);

        Mail::assertSent(AppointmentReminder::class, fn ($mail) => $mail->hasTo('angela@example.com')
            && $mail->appointment->is($appointment));
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_no_reminder_for_an_appointment_on_a_different_day(): void
    {
        Mail::fake();
        Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->addDays(2)->setTime(9, 0),
            'end_time' => now()->addDays(2)->setTime(9, 30),
        ]);
        Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->setTime(9, 0),
            'end_time' => now()->setTime(9, 30),
        ]);

        $this->artisan('appointments:send-reminders');

        Mail::assertNothingSent();
    }

    public function test_no_reminder_for_a_non_scheduled_appointment_tomorrow(): void
    {
        Mail::fake();
        Appointment::factory()->requested()->create([
            'preferred_date' => now()->addDay()->toDateString(),
        ]);
        Appointment::factory()->create([
            'status' => 'cancelled',
            'start_time' => now()->addDay()->setTime(9, 0),
            'end_time' => now()->addDay()->setTime(9, 30),
        ]);

        $this->artisan('appointments:send-reminders');

        Mail::assertNothingSent();
    }

    public function test_a_reminder_already_sent_is_not_sent_again(): void
    {
        Mail::fake();
        Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->addDay()->setTime(9, 0),
            'end_time' => now()->addDay()->setTime(9, 30),
            'reminder_sent_at' => now(),
        ]);

        $this->artisan('appointments:send-reminders');

        Mail::assertNothingSent();
    }

    public function test_a_mail_failure_for_one_appointment_does_not_block_the_rest(): void
    {
        $patient = Patient::factory()->create(['email' => 'angela@example.com']);
        $failing = Appointment::factory()->create([
            'status' => 'scheduled',
            'start_time' => now()->addDay()->setTime(9, 0),
            'end_time' => now()->addDay()->setTime(9, 30),
        ]);
        $succeeding = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'status' => 'scheduled',
            'start_time' => now()->addDay()->setTime(11, 0),
            'end_time' => now()->addDay()->setTime(11, 30),
        ]);

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('mail transport unavailable'));
        Mail::shouldReceive('to->send')
            ->once()
            ->andReturnNull();

        $this->artisan('appointments:send-reminders')->assertExitCode(0);

        $this->assertNull($failing->fresh()->reminder_sent_at);
        $this->assertNotNull($succeeding->fresh()->reminder_sent_at);
    }
}
