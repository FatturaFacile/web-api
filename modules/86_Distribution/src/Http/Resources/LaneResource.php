<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\DistributionLane;

/**
 * @mixin DistributionLane
 */
final class LaneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = static fn ($warehouse): ?array => $warehouse === null ? null : ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];

        return [
            'id' => $this->id,
            'source_warehouse_id' => $this->source_warehouse_id,
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'lead_time_days' => $this->lead_time_days,
            'lead_time_hours' => $this->lead_time_hours,
            'transport_mode' => $this->transport_mode->value,
            'cost_per_unit' => $this->cost_per_unit,
            'is_active' => $this->is_active,
            'source_warehouse' => $this->relationLoaded('sourceWarehouse') ? $summary($this->sourceWarehouse) : null,
            'destination_warehouse' => $this->relationLoaded('destinationWarehouse') ? $summary($this->destinationWarehouse) : null,
        ];
    }
}
