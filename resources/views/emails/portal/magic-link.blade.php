<x-mail::message>
# {{ __('portal.mail.magic_link.greeting', ['name' => $contact->first_name ?: $contact->company_name]) }}

{{ __('portal.mail.magic_link.intro', ['company' => $companyName, 'minutes' => $minutes]) }}

<x-mail::button :url="$url">
{{ __('portal.mail.magic_link.action') }}
</x-mail::button>

> {{ __('portal.mail.magic_link.security') }}
</x-mail::message>
