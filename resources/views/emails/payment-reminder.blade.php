@php
    $invoice = $reminder->invoice;
    $contact = $invoice->contact;
    $level = min(max($reminder->level, 1), 3);
@endphp
<p>{{ __('emails.greeting', ['name' => $contact?->company_name ?: trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))]) }}</p>

<p>{!! __("emails.payment_reminder.body.level_{$level}", ['number' => e($invoice->invoice_number), 'due_date' => e($invoice->due_date?->format('Y-m-d'))]) !!}</p>

<p>{!! __('emails.payment_reminder.amount_due', ['amount' => number_format((float) $reminder->amount_due, 2), 'currency' => e($invoice->currency)]) !!}</p>

<p>{{ __('emails.closing') }}</p>