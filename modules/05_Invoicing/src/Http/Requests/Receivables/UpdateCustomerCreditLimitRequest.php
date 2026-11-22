<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\CustomerCreditLimit;

/**
 * Partial update of a credit limit record (the customer of a record never changes).
 */
final class UpdateCustomerCreditLimitRequest extends FormRequest
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
        return [
            'currency' => ['sometimes', 'string', 'size:3'],
            'granted_limit' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'insured_limit' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'insurer_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'policy_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'coverage_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'max_overdue_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'overdue_tolerance_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'reaction_policy' => ['sometimes', Rule::in(CustomerCreditLimit::Reactions)],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
