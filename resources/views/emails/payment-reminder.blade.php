@php
    $invoice = $reminder->invoice ?? ($reminder->invoice_id ? \App\Models\Invoice::withoutGlobalScopes()->find($reminder->invoice_id) : null);
    $contact = $invoice?->contact ?? ($invoice?->contact_id ? \App\Models\Contact::withoutGlobalScopes()->find($invoice->contact_id) : null);
    $level = min(max($reminder->level, 1), 3);
    $currency = e($invoice?->currency ?? '');
@endphp
<p>{{ __('emails.greeting', ['name' => $contact?->company_name ?: trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))]) }}</p>

<p>{!! __("emails.payment_reminder.body.level_{$level}", ['number' => e($invoice?->invoice_number ?? ''), 'due_date' => e($invoice?->due_date?->format('Y-m-d') ?? '')]) !!}</p>

<p>{!! __('emails.payment_reminder.amount_due', ['amount' => number_format((float) $reminder->amount_due, 2), 'currency' => $currency]) !!}</p>

@if((float) ($reminder->dunning_fee ?? 0) > 0 || (float) ($reminder->interest_amount ?? 0) > 0)
<ul>
    @if((float) ($reminder->dunning_fee ?? 0) > 0)
        <li>Dunning fee (Level {{ $level }}): {{ number_format((float) $reminder->dunning_fee, 2) }} {{ $currency }}</li>
    @endif
    @if((float) ($reminder->interest_amount ?? 0) > 0)
        <li>Default interest (Swiss CO Art. 104 — {{ number_format((float) ($reminder->interest_rate_percent ?? 5), 2) }}% p.a.): {{ number_format((float) $reminder->interest_amount, 2) }} {{ $currency }}</li>
    @endif
    <li><strong>Total claimed amount: {{ number_format((float) ($reminder->total_claimed_amount ?: $reminder->amount_due), 2) }} {{ $currency }}</strong></li>
</ul>
@endif

@if($reminder->qr_bill_attached && $reminder->qr_reference)
<p>Swiss QR-Bill Reference: <strong>{{ $reminder->qr_reference }}</strong></p>
@endif

<p>{{ __('emails.closing') }}</p>
