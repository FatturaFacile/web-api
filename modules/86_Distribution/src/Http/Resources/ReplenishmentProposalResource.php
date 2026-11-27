<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\ReplenishmentProposal;

/**
 * @mixin ReplenishmentProposal
 */
final class ReplenishmentProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = static fn ($warehouse): ?array => $warehouse === null ? null : ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];

        return [
            'id' => $this->id,
            'proposal_type' => $this->proposal_type->value,
            'status' => $this->status->value,
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,
            'source_warehouse_id' => $this->source_warehouse_id,
            'suggested_quantity' => $this->suggested_quantity,
            'original_quantity' => $this->original_quantity,
            'uom' => $this->whenLoaded('item', fn (): string => (string) $this->item->uom),
            'projected_stockout_date' => $this->projected_stockout_date?->toDateString(),
            'source_available_quantity' => $this->source_available_quantity,
            'shortfall_quantity' => $this->shortfall_quantity,
            'rule_id' => $this->rule_id,
            'transfer_order_id' => $this->transfer_order_id,
            'calculation' => $this->calculation ?? [],
            'generated_at' => $this->generated_at?->toIso8601String(),
            'warehouse' => $this->relationLoaded('warehouse') ? $summary($this->warehouse) : null,
            'source_warehouse' => $this->relationLoaded('sourceWarehouse') ? $summary($this->sourceWarehouse) : null,
            'item' => $this->whenLoaded('item', fn (): array => ['id' => $this->item->id, 'sku' => $this->item->sku, 'name' => $this->item->name, 'uom' => $this->item->uom]),
        ];
    }
}
