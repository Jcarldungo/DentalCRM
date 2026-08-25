@component('mail::message')
# We couldn't confirm your appointment request

Hi {{ $appointment->patient->full_name }},

Unfortunately we weren't able to confirm your appointment request for the
following:

@component('mail::panel')
**Service:** {{ $appointment->service_interest }}<br>
**Preferred date:** {{ $appointment->preferred_date->format('l, F j, Y') }}<br>
**Preferred time:** {{ ucfirst($appointment->preferred_time_of_day) }}
@endcomponent

Please call us at {{ config('clinic.contact_phone') }} or email
{{ config('clinic.contact_email') }} to find another time, or submit a new
request from our website.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
