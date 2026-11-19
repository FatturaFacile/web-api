<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the VarianceQueryRequest payload; every reference is limited to the active company.
 */
final class VarianceQueryRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'budget_id' => ['nullable', 'uuid', Rule::exists('controlling_budget_plans', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')], 'forecast_id' => ['nullable', 'uuid', Rule::exists('controlling_budget_plans', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'group_by' => ['nullable', Rule::in(['nature', 'cost_center', 'job', 'product_family', 'customer', 'agent', 'department', 'geography'])],
            'period_mode' => ['nullable', Rule::in(['monthly', 'ytd', 'full_year_eac'])], 'month' => ['nullable', 'integer', 'between:1,12'], 'fiscal_year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
