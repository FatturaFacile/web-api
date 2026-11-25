<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * A transfer of budget between two lines of a draft version in the same period.
 */
final class TransferRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'array'],
            'from.account_id' => ['required', 'uuid'],
            'from.cost_center_id' => ['nullable', 'uuid'],
            'from.project_id' => ['nullable', 'uuid'],
            'to' => ['required', 'array'],
            'to.account_id' => ['required', 'uuid'],
            'to.cost_center_id' => ['nullable', 'uuid'],
            'to.project_id' => ['nullable', 'uuid'],
            'period' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
