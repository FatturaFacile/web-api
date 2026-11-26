<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('advanced_auth.mail.unlocked.intro', ['app' => config('app.name')]) }}

> {{ __('advanced_auth.mail.unlocked.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
