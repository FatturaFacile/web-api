<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A metered usage record of a subscription item of the company; the idempotency key makes retries safe.
 */
final class RecordUsageRequest extends FormRequest
{
    use TenantRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subscription_item_id' => ['required', 'uuid', $this->existsInTenant('sub_subscription_items')],
            'metric_code' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'event_timestamp' => ['nullable', 'date', 'before_or_equal:now'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
