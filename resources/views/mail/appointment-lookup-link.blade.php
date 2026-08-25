@component('mail::message')
# View your appointments

Hi {{ $patient->first_name }},

You (or someone using this email address) asked to view your appointments at
{{ config('app.name') }}. Click below to see them — this link expires in 30
minutes.

@component('mail::button', ['url' => $url])
View my appointments
@endcomponent

If you didn't request this, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
