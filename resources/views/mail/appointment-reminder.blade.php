@component('mail::message')
# See you tomorrow

Hi {{ $appointment->patient->full_name }},

This is a reminder about your upcoming appointment.

@component('mail::panel')
**Service:** {{ $appointment->service_interest ?? ucfirst($appointment->type) }}<br>
**Provider:** {{ $appointment->provider->name }}<br>
**Date:** {{ $appointment->start_time->format('l, F j, Y') }}<br>
**Time:** {{ $appointment->start_time->format('g:i A') }} – {{ $appointment->end_time->format('g:i A') }}
@endcomponent

Need to reschedule or cancel? Call us at {{ config('clinic.contact_phone') }}
or email {{ config('clinic.contact_email') }}.

See you soon,<br>
{{ config('app.name') }}
@endcomponent
