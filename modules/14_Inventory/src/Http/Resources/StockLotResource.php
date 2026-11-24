<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_item_id' => $this->stock_item_id,
            'lot_number' => $this->lot_number,
            'lot_level' => $this->lot_level,
            'supplier_lot_number' => $this->supplier_lot_number,
            'supplier_id' => $this->supplier_id,
            'manufacturing_date' => $this->manufacturing_date?->toDateString(),
            'expiration_date' => $this->expiration_date?->toDateString(),
            'received_at' => $this->received_at?->toDateString(),
            'is_quarantined' => (bool) $this->is_quarantined,
            'certification_scheme' => $this->certification_scheme,
            'certification_reference' => $this->certification_reference,
            'certification_date' => $this->certification_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
