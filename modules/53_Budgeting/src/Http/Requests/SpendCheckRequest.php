<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetSpendRule;

/**
 * A "what if" of the spend lock: would this spend pass the budget? Nothing is recorded.
 */
final class SpendCheckRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::in([BudgetCommitment::Requisition, BudgetCommitment::PurchaseOrder])],
            'stage' => ['nullable', Rule::in(BudgetSpendRule::Stages)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'account_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
