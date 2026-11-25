<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * A budget line: the amount of an account (and cost centre and project) in a fiscal period. Saving the same combination
 * again replaces the amount.
 */
final class LineRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'account_id' => ['required', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'period' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
