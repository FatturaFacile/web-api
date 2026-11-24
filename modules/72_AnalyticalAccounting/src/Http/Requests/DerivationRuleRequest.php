<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;

/**
 * Validation of an analytical derivation rule: match criteria and target dimensions.
 */
final class DerivationRuleRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'priority' => ['required', 'integer', 'between:0,100000'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'accounting_cause_id' => ['nullable', 'uuid'],
            'journal_type' => ['nullable', 'string', 'max:40'],
            'product_id' => ['nullable', 'uuid'],
            'product_category_id' => ['nullable', 'uuid'],
            'account_id' => ['nullable', 'uuid'],
            'counterparty_type' => ['nullable', Rule::in(['customer', 'supplier', 'employee'])],
            'counterparty_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'profit_center_id' => ['nullable', 'uuid', $this->owned('analytical_centers')],
            'project_id' => ['nullable', 'uuid'],
            'product_line_id' => ['nullable', 'uuid'],
            'cost_element_type' => ['nullable', Rule::in(CostElementType::values())],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $this->all();
            $criteria = ['accounting_cause_id', 'journal_type', 'product_id', 'product_category_id', 'account_id', 'counterparty_type', 'counterparty_id'];
            $targets = ['cost_center_id', 'profit_center_id', 'project_id', 'product_line_id', 'cost_element_type'];
            if (array_filter($criteria, static fn (string $k): bool => ! empty($data[$k])) === []) {
                $validator->errors()->add('criteria', 'At least one match criterion is required.');
            }
            if (array_filter($targets, static fn (string $k): bool => ! empty($data[$k])) === []) {
                $validator->errors()->add('targets', 'At least one target dimension is required.');
            }
        });
    }
}
