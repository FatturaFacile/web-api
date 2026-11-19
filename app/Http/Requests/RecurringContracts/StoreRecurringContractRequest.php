<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringContracts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = auth()->user()?->current_tenant_id ? (string) auth()->user()->current_tenant_id : (string) request()->header('X-Tenant-Id');

        return [
            'customer_id' => ['required', 'uuid', 'exists:contacts,id'],
            'contract_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('recurring_contracts', 'contract_number')->where('tenant_id', $tenantId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'contract_type' => ['required', 'string', Rule::in(['maintenance', 'rental', 'software_license', 'service_subscription'])],
            'status' => ['nullable', 'string', Rule::in(['draft', 'active', 'suspended', 'expired', 'cancelled'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'billing_frequency' => ['required', 'string', Rule::in(['monthly', 'quarterly', 'semi_annual', 'annual', 'custom_days'])],
            'custom_interval_days' => ['nullable', 'required_if:billing_frequency,custom_days', 'integer', 'min:1'],
            'billing_timing' => ['required', 'string', Rule::in(['in_advance', 'in_arrears'])],
            'next_billing_date' => ['nullable', 'date'],
            'auto_renew' => ['nullable', 'boolean'],
            'renewal_period_months' => ['nullable', 'integer', 'min:1'],
            'notice_period_days' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', Rule::in(['CHF', 'EUR'])],
            'payment_term_id' => ['nullable', 'uuid', 'exists:payment_terms,id'],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'invoice_auto_issue' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],

            // Contract Lines
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.vat_rate_id' => ['nullable', 'uuid'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.proration_mode' => ['nullable', 'string', Rule::in(['none', 'daily_exact', 'thirty_day_month'])],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // Optional Indexation clause
            'indexation_clause' => ['nullable', 'array'],
            'indexation_clause.index_type' => ['required_with:indexation_clause', 'string', Rule::in(['none', 'istat_foi', 'swiss_ipc'])],
            'indexation_clause.base_index_value' => ['required_with:indexation_clause', 'numeric', 'gt:0'],
            'indexation_clause.base_index_period' => ['required_with:indexation_clause', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'indexation_clause.adjustment_frequency_months' => ['nullable', 'integer', 'min:1'],
            'indexation_clause.pass_through_percent' => ['nullable', 'numeric', 'between:0,100'],
            'indexation_clause.floor_percent' => ['nullable', 'numeric'],
            'indexation_clause.cap_percent' => ['nullable', 'numeric'],
            'indexation_clause.next_adjustment_date' => ['required_with:indexation_clause', 'date'],
        ];
    }
}
