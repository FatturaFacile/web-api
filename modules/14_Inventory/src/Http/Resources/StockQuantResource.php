<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockQuantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_bin_id' => $this->warehouse_bin_id,
            'stock_item_id' => $this->stock_item_id,
            'lot_id' => $this->lot_id,
            'quantity' => (float) $this->quantity_on_hand,
            'reserved_quantity' => (float) $this->quantity_reserved,
            'available_quantity' => $this->quantity_available,
            'bin' => new WarehouseBinResource($this->whenLoaded('bin')),
            'item' => new StockItemResource($this->whenLoaded('item')),
            'lot' => $this->whenLoaded('lot'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
