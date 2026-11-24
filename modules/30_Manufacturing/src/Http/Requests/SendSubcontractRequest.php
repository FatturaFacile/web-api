<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of components sent to a subcontractor for a subcontracted operation.
 */
final class SendSubcontractRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_order_operation_id' => ['required', 'uuid', $this->tenantOwns('mfg_production_order_operations')],
            'subcontractor_contact_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'subcontract_warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'source_warehouse_id' => ['nullable', 'uuid', 'different:subcontract_warehouse_id', $this->tenantExists('warehouses')],
            'quantity' => ['required', ...self::PositiveQuantity],
            'unit_cost' => ['nullable', ...self::Amount],
            'posting_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'components' => ['nullable', 'array', 'max:500'],
            'components.*.line_id' => ['required', 'uuid', 'distinct'],
            'components.*.quantity' => ['required', ...self::PositiveQuantity],
        ];
    }
}
