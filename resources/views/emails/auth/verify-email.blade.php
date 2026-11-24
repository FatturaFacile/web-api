<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('registration.mail.verify.intro', ['app' => config('app.name')]) }}

<x-mail::button :url="$link">
{{ __('registration.mail.verify.action') }}
</x-mail::button>

{{ __('registration.mail.verify.expires', ['date' => $expiresAt->copy()->locale(app()->getLocale())->isoFormat('LLL')]) }}

{{ __('registration.mail.verify.approval') }}

> {{ __('registration.mail.verify.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
