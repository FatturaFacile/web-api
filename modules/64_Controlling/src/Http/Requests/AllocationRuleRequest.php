<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\CostAllocationRule;

/**
 * Validation of the AllocationRuleRequest payload; every reference is limited to the active company.
 */
final class AllocationRuleRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'name' => ['required', 'string', 'max:160'],
            'source_cost_center_id' => ['required', 'uuid', Rule::exists('analytical_cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'driver_type' => ['required', Rule::in(CostAllocationRule::Drivers)],
            'step_order' => ['nullable', 'integer', 'min:1', 'max:99'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.type' => ['required', Rule::in(['cost_center', 'job'])],
            'recipients.*.id' => ['required', 'uuid'],
            'recipients.*.weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
