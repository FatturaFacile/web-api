<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Support\Decimal;

/**
 * @mixin InventoryCostLayer
 */
final class InventoryCostLayerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryCostLayer $layer */
        $layer = $this->resource;

        return [
            'id' => $layer->id,
            'item_id' => $layer->stock_item_id,
            'warehouse_id' => $layer->warehouse_id,
            'lot_id' => $layer->lot_id,
            'source_type' => $layer->source_type,
            'received_at' => $layer->received_at->toIso8601String(),
            'original_quantity' => Decimal::quantity((string) $layer->original_quantity),
            'remaining_quantity' => Decimal::quantity((string) $layer->remaining_quantity),
            'unit_cost' => Decimal::unitCost((string) $layer->unit_cost),
            'landed_cost_unit' => Decimal::unitCost((string) $layer->landed_cost_unit),
            'effective_unit_cost' => $layer->effectiveUnitCost(),
            'currency' => $layer->currency,
            'receipt_id' => $layer->receipt_id,
        ];
    }
}
