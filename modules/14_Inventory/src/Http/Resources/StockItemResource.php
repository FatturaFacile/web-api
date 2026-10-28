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
            'moving_average_cost' => (float) $this->moving_average_cost,
            'weight_kg' => (float) $this->weight_kg,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category'),
            'quants' => StockQuantResource::collection($this->whenLoaded('quants')),
            'lots' => $this->whenLoaded('lots'),
            'reorder_rules' => $this->whenLoaded('reorderRules'),
            'total_on_hand' => (float) ($this->quants_sum_quantity ?? 0.0),
            'total_available' => (float) ($this->quants_sum_available_quantity ?? 0.0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
