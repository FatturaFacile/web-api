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
            'warehouse_id' => $this->warehouse_id,
            'warehouse_bin_id' => $this->warehouse_bin_id,
            'stock_item_id' => $this->stock_item_id,
            'lot_id' => $this->lot_id,
            'serial_number_id' => $this->serial_number_id,
            'quantity' => (float) $this->quantity,
            'reserved_quantity' => (float) $this->reserved_quantity,
            'available_quantity' => (float) $this->available_quantity,
            'cost_basis' => (float) $this->cost_basis,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'bin' => new WarehouseBinResource($this->whenLoaded('bin')),
            'item' => new StockItemResource($this->whenLoaded('item')),
            'lot' => $this->whenLoaded('lot'),
            'serial_number' => $this->whenLoaded('serialNumber'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
