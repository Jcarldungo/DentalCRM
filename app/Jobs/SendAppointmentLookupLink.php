<?php

namespace App\Jobs;

use App\Mail\AppointmentLookupLink;
use App\Models\Patient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves a submitted email to a patient and, if one matches, mails them
 * their signed lookup link.
 *
 * The match happens here rather than in the controller so that
 * POST /my-appointments performs identical work whether or not the
 * address belongs to a patient — the endpoint's no-enumeration guarantee
 * (see AppointmentLookupLink's docblock) then holds against response
 * timing, not only against response shape.
 *
 * A queue worker must be running for the link to actually go out;
 * `composer run dev` starts one.
 */
class SendAppointmentLookupLink implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $email  Already normalized to lowercase by the caller.
     */
    public function __construct(public readonly string $email)
    {
    }

    public function handle(): void
    {
        $patient = Patient::whereRaw('LOWER(email) = ?', [$this->email])->first();

        if (! $patient) {
            return;
        }

        Mail::to($patient->email)->send(new AppointmentLookupLink($patient));
    }
}
