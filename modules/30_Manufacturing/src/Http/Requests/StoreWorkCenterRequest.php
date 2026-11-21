<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\WorkCenterStatus;

/**
 * Validation of a new work centre or of changes to one (PUT: every field optional, the code unique in the company).
 */
final class StoreWorkCenterRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $updating ? 'sometimes' : 'required';

        return [
            'work_center_code' => [$required, 'string', 'max:50', Rule::unique('mfg_work_centers', 'work_center_code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'department_code' => ['nullable', 'string', 'max:50'],
            'capacity_hours_per_day' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'efficiency_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:200'],
            'availability_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'hourly_cost_rate' => ['sometimes', ...self::Amount],
            'hourly_overhead_rate' => ['sometimes', ...self::Amount],
            'status' => ['sometimes', 'string', Rule::in(WorkCenterStatus::values())],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
            'working_calendar_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_working_calendars')],
            'parent_work_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'parallel_capacity_units' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'oee_target_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'availability_target_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'performance_target_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'quality_target_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'hourly_setup_cost_rate' => ['sometimes', ...self::Amount],
            'hourly_machine_run_cost_rate' => ['sometimes', ...self::Amount],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
            'default_queue_wait_minutes' => ['sometimes', 'integer', 'min:0', 'max:9999999'],
            'default_move_time_minutes' => ['sometimes', 'integer', 'min:0', 'max:9999999'],
            'alternate_work_center_ids' => ['nullable', 'array', 'max:20'],
            'alternate_work_center_ids.*' => ['uuid', 'distinct', $this->tenantOwns('mfg_work_centers')],
        ];
    }
}
