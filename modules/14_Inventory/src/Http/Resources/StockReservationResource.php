<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_item_id' => $this->stock_item_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_bin_id' => $this->warehouse_bin_id,
            'source_document_type' => $this->source_document_type,
            'source_document_id' => $this->source_document_id,
            'quantity_reserved' => (float) $this->quantity_reserved,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'allocated_by' => $this->allocated_by,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'bin' => new WarehouseBinResource($this->whenLoaded('bin')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
