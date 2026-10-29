<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_locked' => (bool) $this->is_locked,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'zone' => $this->whenLoaded('zone'),
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
