<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A billing run: date, lookahead and, optionally, one subscription of the company.
 */
final class ExecuteBillingRunRequest extends FormRequest
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
            'run_date' => ['nullable', 'date', 'before_or_equal:today'],
            'lookahead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'subscription_id' => ['nullable', 'uuid', $this->existsInTenant('sub_subscriptions')],
        ];
    }
}
