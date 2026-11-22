<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\CustomerCreditLimit;

/**
 * Credit limit of a customer for a validity period, with insurance data, overdue tolerance and reaction policy.
 */
final class StoreCustomerCreditLimitRequest extends FormRequest
{
    use TenantScopedRules;

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
            'customer_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'currency' => ['required', 'string', 'size:3'],
            'granted_limit' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'insured_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'insurer_name' => ['nullable', 'string', 'max:150'],
            'policy_number' => ['nullable', 'string', 'max:100'],
            'coverage_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'max_overdue_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'overdue_tolerance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'reaction_policy' => ['required', Rule::in(CustomerCreditLimit::Reactions)],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
