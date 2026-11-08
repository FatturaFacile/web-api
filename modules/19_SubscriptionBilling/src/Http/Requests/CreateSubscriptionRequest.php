<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new subscription of a customer of the company to an active plan of the company.
 */
final class CreateSubscriptionRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid', $this->existsInTenant('contacts')],
            'plan_id' => ['required', 'uuid', $this->existsInTenant('sub_plans')->where('is_active', true)],
            'name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'billing_alignment' => ['nullable', 'string', 'in:anniversary,calendar'],
            'contract_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'auto_renew' => ['nullable', 'boolean'],
            'renewal_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'cancellation_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'renewal_price_increase_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'billing_day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_method' => ['nullable', 'string', 'in:sepa_direct_debit,bank_transfer,credit_card,cash'],
            'bank_account_id' => ['nullable', 'uuid', $this->existsInTenant('bank_accounts')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.item_type' => ['nullable', 'string', 'in:addon,support_retainer,hardware_lease,charge,usage_meter'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.metric_id' => ['nullable', 'uuid', $this->existsInTenant('sub_usage_metrics')],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
