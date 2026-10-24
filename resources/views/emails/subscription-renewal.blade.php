@php
    $contact = $subscription->contact;
@endphp
<p>{{ __('emails.greeting', ['name' => $contact?->company_name ?: trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))]) }}</p>

<p>
    {!! __('emails.subscription_renewal.body', ['name' => e($subscription->name), 'number' => e($subscription->subscription_number), 'end_date' => e($subscription->end_date?->format('Y-m-d'))]) !!}
    {{ $subscription->auto_renew ? __('emails.subscription_renewal.auto_renew') : __('emails.subscription_renewal.manual_renew') }}
</p>

<p>{{ __('emails.closing') }}</p>