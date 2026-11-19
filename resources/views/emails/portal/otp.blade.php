<x-mail::message>
# {{ __('portal.mail.otp.greeting', ['name' => $contact->first_name ?: $contact->company_name]) }}

{{ __('portal.mail.otp.intro', ['company' => $companyName, 'minutes' => $minutes]) }}

# {{ $code }}

> {{ __('portal.mail.otp.security') }}
</x-mail::message>
