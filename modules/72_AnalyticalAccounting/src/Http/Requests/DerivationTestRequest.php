<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;

/**
 * Validation of the "what would the rules derive" test payload.
 */
final class DerivationTestRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'accounting_cause_id' => ['nullable', 'uuid'],
            'journal_type' => ['nullable', 'string', 'max:40'],
            'product_id' => ['nullable', 'uuid'],
            'product_category_id' => ['nullable', 'uuid'],
            'account_id' => ['nullable', 'uuid'],
            'counterparty_type' => ['nullable', 'string', 'max:20'],
            'counterparty_id' => ['nullable', 'uuid'],
            'explicit' => ['nullable', 'array'],
            'explicit.cost_center_id' => ['nullable', 'uuid'],
            'explicit.profit_center_id' => ['nullable', 'uuid'],
            'explicit.project_id' => ['nullable', 'uuid'],
            'explicit.product_line_id' => ['nullable', 'uuid'],
            'explicit.cost_element_type' => ['nullable', Rule::in(CostElementType::values())],
        ];
    }
}
