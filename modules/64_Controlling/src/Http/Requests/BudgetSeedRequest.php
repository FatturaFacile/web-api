<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the BudgetSeedRequest payload; every reference is limited to the active company.
 */
final class BudgetSeedRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('controlling_budget_plans', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:160'], 'fiscal_year' => ['required', 'integer', 'between:2000,2100'], 'uplift_percent' => ['nullable', 'numeric', 'between:-100,1000'],
        ];
    }
}
