<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('two_factor.mail.code.intro', ['app' => config('app.name')]) }}

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

{{ __('two_factor.mail.code.validity', ['minutes' => $minutes]) }}

> {{ __('two_factor.mail.code.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
