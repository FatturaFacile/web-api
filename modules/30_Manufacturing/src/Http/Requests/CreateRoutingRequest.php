<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a new routing with its operations.
 */
final class CreateRoutingRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mfg_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'name' => ['required', 'string', 'max:200'],
            'revision' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'operations' => ['required', 'array', 'min:1', 'max:200'],
            'operations.*.sequence' => ['nullable', 'integer', 'min:1', 'max:99999', 'distinct'],
            'operations.*.operation_code' => ['required', 'string', 'max:50'],
            'operations.*.name' => ['required', 'string', 'max:200'],
            'operations.*.description' => ['nullable', 'string', 'max:5000'],
            'operations.*.work_center_id' => ['required', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'operations.*.machine_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
            'operations.*.setup_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.run_time_minutes_per_unit' => ['nullable', ...self::Minutes],
            'operations.*.labor_time_minutes_per_unit' => ['nullable', ...self::Minutes],
            'operations.*.queue_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.wait_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.teardown_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.is_subcontracted' => ['nullable', 'boolean'],
            'operations.*.subcontractor_contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'operations.*.subcontract_unit_cost' => ['nullable', ...self::Amount],
            'operations.*.quality_plan_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_quality_plans')],
        ];
    }
}
