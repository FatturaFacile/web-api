<x-mail::message>
# {{ __('registration.mail.greeting', ['name' => $user->first_name ?? $user->name]) }}

{{ __("registration.mail.decision.{$status}_body", ['app' => config('app.name')]) }}

@if ($status === 'approved')
<x-mail::button :url="rtrim((string) config('app.frontend_url'), '/').'/login'">
{{ __('registration.mail.decision.action') }}
</x-mail::button>
@endif

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
