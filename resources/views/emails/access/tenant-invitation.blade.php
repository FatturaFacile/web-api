<x-mail::message>
# {{ __('access.mail.invitation.title', ['tenant' => $tenantName]) }}

@if ($inviterName)
{{ __('access.mail.invitation.intro_by', ['name' => $inviterName, 'tenant' => $tenantName, 'app' => config('app.name')]) }}
@else
{{ __('access.mail.invitation.intro', ['tenant' => $tenantName, 'app' => config('app.name')]) }}
@endif

<x-mail::button :url="$url">
{{ __('access.mail.invitation.action') }}
</x-mail::button>

{{ __('access.mail.invitation.expires', ['date' => $expiresAt->isoFormat('LLL')]) }}

> {{ __('access.mail.invitation.ignore') }}

{{ __('registration.mail.signature', ['app' => config('app.name')]) }}
</x-mail::message>
