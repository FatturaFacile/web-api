<p>{{ __('emails.greeting', ['name' => $recipientName]) }}</p>

<p>
    {!! __('emails.subscription_indexation.body', [
        'number' => e($subscription->subscription_number),
        'old' => number_format((float) $adjustment->old_recurring_amount, 2, '.', ''),
        'new' => number_format((float) $adjustment->new_recurring_amount, 2, '.', ''),
        'currency' => e($subscription->currency),
        'date' => e($adjustment->effective_date?->format('Y-m-d')),
    ]) !!}
</p>

<p>
    {{ __('emails.subscription_indexation.calculation', [
        'index' => strtoupper((string) $adjustment->index_type),
        'base_index' => number_format((float) $adjustment->base_index, 4, '.', ''),
        'base_month' => $adjustment->base_month?->format('Y-m'),
        'new_index' => number_format((float) $adjustment->new_index, 4, '.', ''),
        'reference_month' => $adjustment->reference_month?->format('Y-m'),
        'variation' => number_format((float) $adjustment->variation_pct, 4, '.', ''),
        'applied' => number_format((float) $adjustment->applied_pct, 4, '.', ''),
    ]) }}
</p>

<p>{{ __('emails.closing') }}</p>
