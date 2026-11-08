<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * A physical count session with its freeze, approval and adjustment.
 *
 * @mixin InventoryCountSession
 */
final class InventoryCountSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_number' => $this->session_number,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_zone_id' => $this->warehouse_zone_id,
            'count_type' => $this->count_type,
            'status' => $this->status,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'effective_at' => $this->effective_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_locked' => (bool) $this->is_locked,
            'freezes_stock' => (bool) $this->freezes_stock,
            'frozen_at' => $this->frozen_at?->toIso8601String(),
            'cycle_count_plan_id' => $this->cycle_count_plan_id,
            'inventory_adjustment_id' => $this->inventory_adjustment_id,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
