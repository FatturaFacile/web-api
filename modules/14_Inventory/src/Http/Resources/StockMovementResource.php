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
        $source = $this->source_location_id;
        $destination = $this->destination_location_id;
        $quantity = (float) $this->quantity;

        return [
            'id' => $this->id,
            'movement_number' => $this->movement_number ?? substr((string) $this->id, 0, 8),
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,
            'source_bin_id' => $source,
            'destination_bin_id' => $destination,
            'movement_type' => $this->movement_type,
            'quantity' => $quantity,
            'signed_quantity' => $source !== null && $destination === null ? -abs($quantity) : abs($quantity),
            'unit_cost' => (float) $this->unit_cost,
            'total_valuation' => (float) $this->total_valuation,
            'stock_lot_id' => $this->lot_id,
            'stock_serial_number_id' => $this->serial_number_id,
            'reference_type' => $this->origin_document_type,
            'reference_id' => $this->origin_document_id,
            'created_by' => $this->executed_by_user_id,
            'notes' => $this->notes,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'source_bin' => new WarehouseBinResource($this->whenLoaded('sourceBin')),
            'destination_bin' => new WarehouseBinResource($this->whenLoaded('destinationBin')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
