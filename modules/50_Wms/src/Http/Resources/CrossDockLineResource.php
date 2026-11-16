<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsCrossDockLine;

/**
 * A stock item of a cross-dock order with its inbound, received and outbound quantities.
 *
 * @mixin WmsCrossDockLine
 */
final class CrossDockLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cross_dock_order_id' => $this->cross_dock_order_id,
            'stock_item_id' => $this->stock_item_id,
            'inbound_quantity' => $this->inbound_quantity,
            'received_quantity' => $this->received_quantity,
            'outbound_quantity' => $this->outbound_quantity,
            'stock_item' => $this->whenLoaded('stockItem', fn (): ?array => $this->stockItem !== null ? ['id' => $this->stockItem->id, 'sku' => $this->stockItem->sku, 'name' => $this->stockItem->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
