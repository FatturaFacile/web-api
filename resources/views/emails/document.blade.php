<p>{{ __('emails.greeting', ['name' => $summary['contact_name'] ?? '']) }}</p>

<p>{{ __('emails.document.intro.'.$summary['type'], ['number' => $summary['number']]) }}</p>

<ul>
    <li>{{ __('emails.document.number') }}: {{ $summary['number'] }}</li>
    @if ($summary['date'])
        <li>{{ __('emails.document.date') }}: {{ $summary['date'] }}</li>
    @endif
    @if ($summary['total'] !== null)
        <li>{{ __('emails.document.total') }}: {{ number_format($summary['total'], 2) }} {{ $summary['currency'] }}</li>
    @endif
</ul>

@if ($email->message)
    <p>{{ $email->message }}</p>
@endif

<p>{{ __('emails.closing') }}</p>