<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\WorkOrderPart;

/**
 * A spare part issued from the inventory to a work order. Amounts are decimal strings.
 *
 * @mixin WorkOrderPart
 */
final class WorkOrderPartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => $this->quantity !== null ? (string) $this->quantity : null,
            'unit_cost' => $this->unit_cost !== null ? (string) $this->unit_cost : null,
            'total_cost' => $this->total_cost !== null ? (string) $this->total_cost : null,
            'stock_movement_id' => $this->stock_movement_id,
            'consumed_at' => $this->consumed_at?->toIso8601String(),
        ];
    }
}
