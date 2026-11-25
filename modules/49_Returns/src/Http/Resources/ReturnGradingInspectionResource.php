<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\ReturnGradingInspection;

/**
 * @mixin ReturnGradingInspection
 */
final class ReturnGradingInspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_number' => $this->inspection_number,
            'customer_return_id' => $this->customer_return_id,
            'customer_return_line_id' => $this->customer_return_line_id,
            'rma_id' => $this->rma_id,
            'rma_line_id' => $this->rma_line_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'product_sku' => $this->product?->sku,
            'serial_number' => $this->serial_number,
            'lot_number' => $this->lot_number,
            'quantity_inspected' => $this->quantity_inspected,
            'grade' => $this->grade,
            'disposition' => $this->disposition,
            'checklist' => $this->checklist ?? [],
            'photo_urls' => $this->photo_urls,
            'original_unit_cost' => $this->original_unit_cost,
            'devaluation_percent' => $this->devaluation_percent,
            'revalued_unit_cost' => $this->revalued_unit_cost,
            'target_warehouse_id' => $this->target_warehouse_id,
            'supplier_id' => $this->supplier_id,
            'supplier_return_id' => $this->supplier_return_id,
            'refurbished_item_id' => $this->refurbished_item_id,
            'refurbished_sku' => $this->refurbishedItem?->refurbished_sku,
            'stock_movement_id' => $this->stock_movement_id,
            'inspector_notes' => $this->inspector_notes,
            'inspected_at' => $this->inspected_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
