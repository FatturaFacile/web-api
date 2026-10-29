<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_number' => $this->movement_number,
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,
            'source_bin_id' => $this->source_bin_id,
            'destination_bin_id' => $this->destination_bin_id,
            'movement_type' => $this->movement_type,
            'quantity' => (float) $this->quantity,
            'unit_cost' => (float) $this->unit_cost,
            'total_valuation' => (float) $this->total_valuation,
            'stock_lot_id' => $this->stock_lot_id,
            'stock_serial_number_id' => $this->stock_serial_number_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_by' => $this->created_by,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'source_bin' => new WarehouseBinResource($this->whenLoaded('sourceBin')),
            'destination_bin' => new WarehouseBinResource($this->whenLoaded('destinationBin')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
