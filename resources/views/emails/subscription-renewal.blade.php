<p>{{ __('emails.greeting', ['name' => $contact?->company_name ?: trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? ''))]) }}</p>

<p>
    {!! __('emails.subscription_renewal.body', ['name' => e($subscriptionName), 'number' => e($subscription->subscription_number), 'end_date' => e($endDate)]) !!}
    {{ $autoRenew ? __('emails.subscription_renewal.auto_renew') : __('emails.subscription_renewal.manual_renew') }}
</p>

<p>{{ __('emails.closing') }}</p>
