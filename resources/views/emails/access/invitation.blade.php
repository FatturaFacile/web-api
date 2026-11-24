<x-mail::message>
# {{ __('access.mail.invitation.greeting') }}

{{ __('access.mail.invitation.body', ['inviter' => $inviterName, 'company' => $tenantName, 'role' => $role, 'app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('access.mail.invitation.action') }}
</x-mail::button>

{{ __('access.mail.invitation.expires', ['date' => $expiresAt]) }}

@if (config('mail.support_address'))
{{ __('registration.mail.support', ['email' => config('mail.support_address')]) }}
@endif

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
