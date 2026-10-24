<h2>{{ $title }}</h2>
@if (! empty($signature['signed_by']) || ! empty($signature['image']))
    @if (! empty($signature['image']))
        <img src="{{ $signature['image'] }}" alt="" style="max-height: 70px; max-width: 220px;"><br>
    @endif
    {{ __('pdf.signature.signed_by', ['name' => $signature['signed_by']]) }}
    @if (! empty($signature['signed_at'])) {{ __('pdf.signature.signed_on', ['date' => $signature['signed_at']]) }} @endif
@else
    <div class="signature-line muted">{{ __('pdf.signature.line') }}</div>
@endif