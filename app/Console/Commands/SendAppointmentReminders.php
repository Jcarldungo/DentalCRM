<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = "Email patients a reminder for tomorrow's scheduled appointments";

    public function handle(): int
    {
        $tomorrow = now()->addDay();

        $appointments = Appointment::with(['patient', 'provider'])
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->whereDate('start_time', $tomorrow->toDateString())
            ->orderBy('start_time')
            ->get();

        foreach ($appointments as $appointment) {
            try {
                Mail::to($appointment->patient->email)->send(new AppointmentReminder($appointment));

                $appointment->forceFill(['reminder_sent_at' => now()])->save();
            } catch (\Throwable $e) {
                // A failed send here means this appointment won't be picked
                // up again — by tomorrow its start_time is no longer "in the
                // future," so it falls out of the query above for good. That
                // gap is accepted at this scale (see CLAUDE.md Known gaps),
                // same risk tolerance as the confirm/decline mail failure.
                Log::warning('Failed to email an appointment reminder.', [
                    'appointment_id' => $appointment->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
