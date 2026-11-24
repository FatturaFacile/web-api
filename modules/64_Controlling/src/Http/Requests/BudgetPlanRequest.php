<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\BudgetPlan;

/**
 * Validation of the BudgetPlanRequest payload; every reference is limited to the active company.
 */
final class BudgetPlanRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('controlling_budget_plans', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:160'], 'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'plan_type' => ['nullable', Rule::in(BudgetPlan::Types)], 'currency_code' => ['nullable', 'string', 'size:3'],
            'cutoff_month' => ['nullable', 'integer', 'between:1,12'], 'notes' => ['nullable', 'string'],
        ];
    }
}
