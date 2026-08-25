@component('mail::message')
# Your appointment is confirmed

Hi {{ $appointment->patient->full_name }},

Good news — your appointment request has been confirmed.

@component('mail::panel')
**Service:** {{ $appointment->service_interest }}<br>
**Provider:** {{ $appointment->provider->name }}<br>
**Date:** {{ $appointment->start_time->format('l, F j, Y') }}<br>
**Time:** {{ $appointment->start_time->format('g:i A') }} – {{ $appointment->end_time->format('g:i A') }}
@endcomponent

If you need to reschedule or cancel, please call us at {{ config('clinic.contact_phone') }}
or email {{ config('clinic.contact_email') }}.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
