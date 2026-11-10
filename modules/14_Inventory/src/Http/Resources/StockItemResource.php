<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'uom' => $this->uom,
            'description' => $this->description,
            'barcode_ean13' => $this->barcode_ean13,
            'barcode_custom' => $this->barcode_custom,
            'tracking_type' => $this->tracking_type,
            'standard_cost' => (float) $this->standard_cost,
            'moving_average_cost' => (float) $this->current_moving_average_cost,
            'weight_kg' => (float) $this->weight_kg,
            'product_id' => $this->product_id,
            'picking_strategy' => $this->picking_strategy,
            'shelf_life_days' => $this->shelf_life_days,
            'min_remaining_shelf_life_days' => $this->min_remaining_shelf_life_days,
            'net_weight_kg' => $this->net_weight_kg,
            'volume_m3' => $this->volume_m3,
            'units_per_carton' => $this->units_per_carton,
            'cartons_per_pallet' => $this->cartons_per_pallet,
            'carton_tare_kg' => $this->carton_tare_kg,
            'pallet_tare_kg' => $this->pallet_tare_kg,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category'),
            'quants' => StockQuantResource::collection($this->whenLoaded('quants')),
            'lots' => $this->whenLoaded('lots'),
            'reorder_rules' => $this->whenLoaded('reorderRules'),
            'total_on_hand' => $this->totalOnHand(),
            'total_available' => $this->totalOnHand() - $this->totalReserved(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * On-hand quantity over every bin: the aggregate of the list query or, on a single item, the loaded quants.
     */
    private function totalOnHand(): float
    {
        if ($this->resource->getAttribute('quants_sum_quantity_on_hand') !== null) {
            return (float) $this->resource->getAttribute('quants_sum_quantity_on_hand');
        }

        return $this->resource->relationLoaded('quants') ? (float) $this->resource->quants->sum('quantity_on_hand') : 0.0;
    }

    /**
     * Reserved quantity over every bin, from the same source as the on-hand quantity.
     */
    private function totalReserved(): float
    {
        if ($this->resource->getAttribute('quants_sum_quantity_reserved') !== null) {
            return (float) $this->resource->getAttribute('quants_sum_quantity_reserved');
        }

        return $this->resource->relationLoaded('quants') ? (float) $this->resource->quants->sum('quantity_reserved') : 0.0;
    }
}
