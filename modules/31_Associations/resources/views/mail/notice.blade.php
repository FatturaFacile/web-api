<x-mail::message>
@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ __('associations.mail.open') }}
</x-mail::button>
@endif

{{ $senderName }}
</x-mail::message>
