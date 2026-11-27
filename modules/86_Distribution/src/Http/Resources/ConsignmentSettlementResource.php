<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\ConsignmentSettlement;

/**
 * @mixin ConsignmentSettlement
 */
final class ConsignmentSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_number' => $this->settlement_number,
            'warehouse' => $this->whenLoaded('warehouse', fn (): array => ['id' => (string) $this->warehouse->id, 'code' => (string) $this->warehouse->code, 'name' => (string) $this->warehouse->name]),
            'direction' => $this->direction,
            'status' => $this->status->value,
            'total_quantity' => $this->total_quantity,
            'total_value' => $this->total_value,
            'settled_at' => $this->settled_at->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn ($line): array => [
                'stock_item_id' => $line->stock_item_id,
                'sku' => (string) $line->item?->sku,
                'name' => (string) $line->item?->name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])->values()->all()),
        ];
    }
}
