<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __('password_reset.mail.changed.intro', ['app' => config('app.name')]) }}

- {{ __('password_reset.mail.changed.date', ['date' => $changedAt->copy()->locale(app()->getLocale())->isoFormat('LLL')]) }}
- {{ __('password_reset.mail.changed.ip', ['ip' => $ipAddress ?? __('password_reset.mail.changed.unknown')]) }}
- {{ __('password_reset.mail.changed.device', ['device' => $device ?? __('password_reset.mail.changed.unknown')]) }}

> {{ __('password_reset.mail.changed.security') }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
