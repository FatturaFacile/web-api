<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Controlling\Models\AnalyticalJob;

/**
 * Validation of the JobRequest payload; every reference is limited to the active company.
 */
final class JobRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('analytical_jobs', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:160'],
            'customer_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')], 'sales_order_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid', Rule::exists('analytical_cost_centers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'status' => ['required', Rule::in(AnalyticalJob::Statuses)],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['nullable', 'numeric', 'min:0'], 'estimated_cost_budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
