<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgItem;

/**
 * A manufacturing item with its planning parameters and standard cost breakdown. Amounts and quantities are decimal
 * strings.
 *
 * @mixin MfgItem
 */
final class MfgItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_code' => $this->item_code,
            'name' => $this->name,
            'description' => $this->description,
            'item_type' => $this->item_type,
            'procurement_type' => $this->procurementType(),
            'stock_item_id' => $this->stock_item_id,
            'product_id' => $this->product_id,
            'default_bom_id' => $this->default_bom_id,
            'default_routing_id' => $this->default_routing_id,
            'default_warehouse_id' => $this->default_warehouse_id,
            'uom' => $this->uom,
            'standard_cost' => $this->standard_cost,
            'standard_material_cost' => $this->standard_material_cost,
            'standard_labor_cost' => $this->standard_labor_cost,
            'standard_overhead_cost' => $this->standard_overhead_cost,
            'standard_subcontract_cost' => $this->standard_subcontract_cost,
            'standard_cost_rolled_up_at' => $this->standard_cost_rolled_up_at?->toIso8601String(),
            'safety_stock' => $this->safety_stock,
            'min_lot_size' => $this->min_lot_size,
            'max_lot_size' => $this->max_lot_size,
            'lot_size_multiple' => $this->lot_size_multiple,
            'lead_time_days' => $this->lead_time_days,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
