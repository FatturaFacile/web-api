<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\OperationType;

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
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'mfg_item_id' => [$required, 'uuid', $this->tenantOwns('mfg_items')],
            'bom_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_boms')],
            'status' => ['sometimes', 'string', 'in:draft,active,obsolete'],
            'name' => [$required, 'string', 'max:200'],
            'revision' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'operations' => [$required, 'array', 'min:1', 'max:200'],
            'operations.*.sequence' => ['nullable', 'integer', 'min:1', 'max:99999', 'distinct'],
            'operations.*.operation_code' => ['required', 'string', 'max:50'],
            'operations.*.name' => ['required', 'string', 'max:200'],
            'operations.*.description' => ['nullable', 'string', 'max:5000'],
            'operations.*.work_center_id' => ['required', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'operations.*.machine_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
            'operations.*.setup_time_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'operations.*.run_time_minutes_per_unit' => ['nullable', 'numeric', 'decimal:0,5', 'min:0', 'max:9999999'],
            'operations.*.labor_time_minutes_per_unit' => ['nullable', ...self::Minutes],
            'operations.*.queue_time_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'operations.*.wait_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.teardown_time_minutes' => ['nullable', ...self::Minutes],
            'operations.*.is_subcontracted' => ['nullable', 'boolean'],
            'operations.*.subcontractor_contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'operations.*.subcontract_unit_cost' => ['nullable', ...self::Amount],
            'operations.*.quality_plan_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_quality_plans')],
            'operations.*.operation_type' => ['nullable', 'string', Rule::in(OperationType::values())],
            'operations.*.post_operation_wait_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'operations.*.move_time_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'operations.*.overlap_transfer_batch_qty' => ['nullable', ...self::Quantity],
            'operations.*.required_operators' => ['nullable', 'integer', 'min:1', 'max:100'],
            'operations.*.alternate_work_center_ids' => ['nullable', 'array', 'max:20'],
            'operations.*.alternate_work_center_ids.*' => ['uuid', 'distinct', $this->tenantOwns('mfg_work_centers')],
            'operations.*.linked_quality_control_point_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_quality_plans')],
            'operations.*.work_instruction_doc_id' => ['nullable', 'uuid', $this->tenantOwns('dms_documents')],
            'operations.*.bom_components' => ['nullable', 'array', 'max:200'],
            'operations.*.bom_components.*.bom_component_id' => ['required', 'uuid', 'distinct', $this->tenantOwns('mfg_bom_components')],
            'operations.*.bom_components.*.backflush_on_completion' => ['nullable', 'boolean'],
            'operations.*.bom_components.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
