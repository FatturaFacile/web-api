<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetSpendRule;

/**
 * A spend rule: allow, warn or block when the spend of an account, cost centre or project goes past a percentage of
 * its budget, for requisitions and/or purchase orders at the chosen stages.
 */
final class SpendRuleRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'account_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'mode' => ['required', Rule::in(BudgetSpendRule::Modes)],
            'threshold_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'document_types' => ['required', 'array', 'min:1'],
            'document_types.*' => ['required', Rule::in(BudgetSpendRule::DocumentTypes)],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*' => ['required', Rule::in(BudgetSpendRule::Stages)],
            'enforce_without_budget' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-100', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
