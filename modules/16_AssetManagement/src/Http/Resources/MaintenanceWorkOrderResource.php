<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaintenanceWorkOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_number' => $this->work_order_number,
            'asset_id' => $this->asset_id,
            'plan_id' => $this->plan_id,
            'maintenance_type' => $this->maintenance_type,
            'priority' => $this->priority,
            'status' => $this->status,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'downtime_hours' => (float) $this->downtime_hours,
            'labor_cost' => (float) $this->labor_cost,
            'parts_cost' => (float) $this->parts_cost,
            'external_service_cost' => (float) $this->external_service_cost,
            'total_cost' => (float) $this->total_cost,
            'capitalize_as_upgrade' => (bool) $this->capitalize_as_upgrade,
            'is_locked' => (bool) $this->is_locked,
        ];
    }
}
