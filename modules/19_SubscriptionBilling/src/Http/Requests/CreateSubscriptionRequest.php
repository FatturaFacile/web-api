<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'plan_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'contract_term_months' => ['nullable', 'integer', 'min:1'],
            'auto_renew' => ['nullable', 'boolean'],
            'billing_day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:sepa_direct_debit,bank_transfer,credit_card,cash'],
            'bank_account_id' => ['nullable', 'uuid'],
            'items' => ['nullable', 'array'],
            'items.*.item_type' => ['nullable', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
