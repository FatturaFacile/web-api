<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\RefurbishedItem;

/**
 * @mixin RefurbishedItem
 */
final class RefurbishedItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'refurbished_sku' => $this->refurbished_sku,
            'original_product_id' => $this->original_product_id,
            'original_product_name' => $this->originalProduct?->name,
            'original_product_sku' => $this->originalProduct?->sku,
            'refurbished_product_id' => $this->refurbished_product_id,
            'return_grading_inspection_id' => $this->return_grading_inspection_id,
            'customer_return_id' => $this->customer_return_id,
            'rma_id' => $this->rma_id,
            'grade' => $this->grade,
            'serial_number' => $this->serial_number,
            'lot_number' => $this->lot_number,
            'quantity' => $this->quantity,
            'original_cost' => $this->original_cost,
            'devaluation_percent' => $this->devaluation_percent,
            'refurbishment_cost' => $this->refurbishment_cost,
            'new_unit_cost' => $this->new_unit_cost,
            'suggested_sale_price' => $this->suggested_sale_price,
            'warehouse_id' => $this->warehouse_id,
            'work_order_status' => $this->work_order_status,
            'work_order_steps' => $this->work_order_steps ?? [],
            'stock_movement_id' => $this->stock_movement_id,
            'is_available' => $this->is_available,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
