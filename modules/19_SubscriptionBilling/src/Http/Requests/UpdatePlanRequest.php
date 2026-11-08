<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changes of a plan that do not alter the price of the billed periods: names, availability, VAT rate of the next
 * invoices, trial, renewal increase and ledger accounts.
 */
final class UpdatePlanRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'trial_period_days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'renewal_price_increase_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'revenue_account_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('chart_of_accounts')],
            'deferred_revenue_account_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('chart_of_accounts')],
        ];
    }
}
