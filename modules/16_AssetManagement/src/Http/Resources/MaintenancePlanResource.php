<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\MaintenancePlan;

/**
 * A preventive maintenance plan triggered by time, meter or both. Amounts are decimal strings.
 *
 * @mixin MaintenancePlan
 */
final class MaintenancePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset_class_id' => $this->asset_class_id,
            'plan_code' => $this->plan_code,
            'name' => $this->name,
            'maintenance_type' => $this->maintenance_type,
            'trigger_type' => $this->trigger_type,
            'frequency_days' => $this->frequency_days !== null ? (int) $this->frequency_days : null,
            'meter_code' => $this->meter_code,
            'meter_interval_units' => $this->meter_interval_units !== null ? (string) $this->meter_interval_units : null,
            'last_meter_reading' => $this->last_meter_reading !== null ? (string) $this->last_meter_reading : null,
            'next_due_meter' => $this->next_due_meter !== null ? (string) $this->next_due_meter : null,
            'lead_time_days' => $this->lead_time_days !== null ? (int) $this->lead_time_days : null,
            'estimated_duration_hours' => $this->estimated_duration_hours !== null ? (string) $this->estimated_duration_hours : null,
            'estimated_cost' => $this->estimated_cost !== null ? (string) $this->estimated_cost : null,
            'last_executed_date' => $this->last_executed_date?->format('Y-m-d'),
            'next_due_date' => $this->next_due_date?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'checklist' => $this->checklist,
        ];
    }
}
