<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a new production order.
 */
final class CreateProductionOrderRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mfg_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'target_quantity' => ['required', ...self::PositiveQuantity],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'bom_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_boms')],
            'routing_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_routings')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'sales_order_id' => ['nullable', 'uuid', $this->tenantOwns('sales_orders')],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'receipt_warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
