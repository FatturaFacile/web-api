<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of changes to a manufacturing item (code and standard cost breakdown cannot be changed here).
 */
final class UpdateItemRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'item_type' => ['sometimes', 'string', 'in:raw_material,subassembly,finished_good,phantom'],
            'procurement_type' => ['nullable', 'string', 'in:make,buy,subcontract'],
            'stock_item_id' => ['nullable', 'uuid', $this->tenantOwns('stock_items')],
            'product_id' => ['nullable', 'uuid', $this->tenantOwns('products')],
            'default_warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'uom' => ['sometimes', 'string', 'max:20'],
            'standard_cost' => ['sometimes', ...self::Amount],
            'safety_stock' => ['sometimes', ...self::Quantity],
            'min_lot_size' => ['sometimes', ...self::Quantity],
            'max_lot_size' => ['nullable', ...self::PositiveQuantity],
            'lot_size_multiple' => ['nullable', ...self::Quantity],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
