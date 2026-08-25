<?php

namespace App\Mail;

use App\Models\Patient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class AppointmentLookupLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Patient $patient)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'View your appointments at '.config('app.name'),
        );
    }

    public function content(): Content
    {
        $url = URL::temporarySignedRoute(
            'appointments.lookup.show',
            now()->addMinutes(30),
            ['patient' => $this->patient->id],
        );

        return new Content(
            markdown: 'mail.appointment-lookup-link',
            with: [
                'patient' => $this->patient,
                'url' => $url,
            ],
        );
    }
}
