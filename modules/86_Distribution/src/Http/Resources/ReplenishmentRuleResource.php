<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\ReplenishmentRule;

/**
 * @mixin ReplenishmentRule
 */
final class ReplenishmentRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = static fn ($warehouse): ?array => $warehouse === null ? null : ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];

        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'stock_item_id' => $this->stock_item_id,
            'policy' => $this->policy->value,
            'source_warehouse_id' => $this->source_warehouse_id,
            'safety_stock' => $this->safety_stock,
            'reorder_point' => $this->reorder_point,
            'max_stock' => $this->max_stock,
            'fixed_quantity' => $this->fixed_quantity,
            'min_transfer_qty' => $this->min_transfer_qty,
            'transfer_multiple' => $this->transfer_multiple,
            'lead_time_days' => $this->lead_time_days,
            'review_cycle_days' => $this->review_cycle_days,
            'target_days_of_supply' => $this->target_days_of_supply,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'warehouse' => $this->relationLoaded('warehouse') ? $summary($this->warehouse) : null,
            'source_warehouse' => $this->relationLoaded('sourceWarehouse') ? $summary($this->sourceWarehouse) : null,
            'item' => $this->whenLoaded('item', fn (): array => ['id' => $this->item->id, 'sku' => $this->item->sku, 'name' => $this->item->name, 'uom' => $this->item->uom]),
        ];
    }
}
