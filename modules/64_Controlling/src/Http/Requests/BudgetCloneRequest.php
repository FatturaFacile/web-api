<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\BudgetPlan;

/**
 * Validation of the BudgetCloneRequest payload; every reference is limited to the active company.
 */
final class BudgetCloneRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('controlling_budget_plans', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:160'], 'uplift_percent' => ['nullable', 'numeric', 'between:-100,1000'],
            'plan_type' => ['nullable', Rule::in(BudgetPlan::Types)],
        ];
    }
}
