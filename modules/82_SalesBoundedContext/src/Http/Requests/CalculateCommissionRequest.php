<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\CommissionAccrualBasis;

final class CalculateCommissionRequest extends FormRequest
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
            'statement_number' => ['nullable', 'string', 'max:40'],
            'agent_name' => ['required', 'string', 'max:160'],
            'agent_jurisdiction' => ['required', Rule::in(['IT', 'CH'])],
            'accrual_basis' => ['required', Rule::in(CommissionAccrualBasis::values())],
            'period_label' => ['nullable', 'string', 'max:24'],
            'currency' => ['nullable', 'string', 'size:3'],
            'commissionable_base' => ['required', 'numeric', 'min:0'],
            'tier_rules' => ['nullable', 'array'],
            'split_override_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
