<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a quality inspection at receipt, operation or final point.
 */
final class RecordQualityInspectionRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quality_plan_id' => ['required', 'uuid', $this->tenantOwns('mfg_quality_plans')],
            'inspection_point' => ['required', 'string', 'in:receipt,operation,final'],
            'production_order_id' => ['nullable', 'required_if:inspection_point,receipt,operation', 'uuid', $this->tenantOwns('mfg_production_orders')],
            'production_order_operation_id' => ['nullable', 'required_if:inspection_point,operation', 'uuid'],
            'material_movement_id' => ['nullable', 'uuid'],
            'sample_size' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'defect_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'quantity_inspected' => ['nullable', ...self::Quantity],
            'quantity_rejected' => ['nullable', ...self::Quantity],
            'notes' => ['nullable', 'string', 'max:5000'],
            'characteristic_results' => ['nullable', 'array', 'max:200'],
            'characteristic_results.*.characteristic_id' => ['required', 'uuid', 'distinct'],
            'characteristic_results.*.measured_value' => ['required', 'numeric', 'decimal:0,4'],
        ];
    }
}
