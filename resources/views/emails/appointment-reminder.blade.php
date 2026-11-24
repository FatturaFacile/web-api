@php
    $contact = $appointment->contact;
@endphp
<p>{{ __('emails.greeting', ['name' => $contact?->company_name ?: trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))]) }}</p>

<p>
    {!! __('emails.appointment_reminder.body', [
        'title' => e($appointment->title),
        'number' => e($appointment->appointment_number),
        'date' => e($appointment->starts_at?->format('d/m/Y')),
        'time' => e($appointment->starts_at?->format('H:i')),
    ]) !!}
</p>

@if (filled($appointment->address))
    <p>{{ __('emails.appointment_reminder.address', ['address' => $appointment->address]) }}</p>
@endif

<p>{{ __('emails.closing') }}</p>
