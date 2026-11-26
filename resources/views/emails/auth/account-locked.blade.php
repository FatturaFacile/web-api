<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('advanced_auth.mail.locked.intro', ['app' => config('app.name'), 'minutes' => $minutes]) }}

- {{ __('advanced_auth.mail.locked.until', ['date' => $lockedUntil->copy()->locale(app()->getLocale())->isoFormat('LLL')]) }}
- {{ __('advanced_auth.mail.locked.ip', ['ip' => $ipAddress ?? __('advanced_auth.mail.unknown')]) }}
- {{ __('advanced_auth.mail.locked.device', ['device' => $device ?? __('advanced_auth.mail.unknown')]) }}

> {{ __('advanced_auth.mail.locked.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
