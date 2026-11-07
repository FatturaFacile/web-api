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
            'source_document_type' => $this->reserved_for_type,
            'source_document_id' => $this->reserved_for_id,
            'quantity_reserved' => (float) $this->quantity_reserved,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'allocated_by' => $this->created_by,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'allocations' => $this->whenLoaded('allocations', fn (): array => $this->allocations->map(static fn ($allocation): array => [
                'stock_quant_id' => $allocation->stock_quant_id,
                'warehouse_bin_id' => $allocation->warehouse_bin_id,
                'lot_id' => $allocation->lot_id,
                'lot_number' => $allocation->lot?->lot_number,
                'expiration_date' => $allocation->lot?->expiration_date?->toDateString(),
                'quantity' => (string) $allocation->quantity,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
