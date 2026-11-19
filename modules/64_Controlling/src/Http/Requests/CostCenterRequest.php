<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\AnalyticalCostCenter;

/**
 * Validation of the CostCenterRequest payload; every reference is limited to the active company.
 */
final class CostCenterRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        $id = $this->route('id');

        return [
            'parent_id' => ['nullable', 'uuid', Rule::exists('analytical_cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at'), Rule::notIn([$id])],
            'code' => ['required', 'string', 'max:40', Rule::unique('analytical_cost_centers', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:160'],
            'center_type' => ['required', Rule::in(AnalyticalCostCenter::Types)],
            'responsible_user_id' => ['nullable', 'uuid'], 'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
