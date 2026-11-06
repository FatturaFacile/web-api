<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('password_reset.mail.reset.intro', ['app' => config('app.name')]) }}

<x-mail::button :url="$link">
{{ __('password_reset.mail.reset.action') }}
</x-mail::button>

{{ __('password_reset.mail.reset.expires', ['date' => $expiresAt->copy()->locale(app()->getLocale())->isoFormat('LLL')]) }}

> {{ __('password_reset.mail.reset.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
