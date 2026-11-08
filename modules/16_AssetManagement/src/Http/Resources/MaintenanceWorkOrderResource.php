<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

/**
 * A maintenance work order with costs, downtime and approval state. Amounts are decimal strings.
 *
 * @mixin MaintenanceWorkOrder
 */
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
            'approval_status' => $this->approval_status,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'downtime_started_at' => $this->downtime_started_at?->toIso8601String(),
            'downtime_ended_at' => $this->downtime_ended_at?->toIso8601String(),
            'downtime_hours' => $this->downtime_hours !== null ? (string) $this->downtime_hours : null,
            'estimated_cost' => $this->estimated_cost !== null ? (string) $this->estimated_cost : null,
            'labor_cost' => $this->labor_cost !== null ? (string) $this->labor_cost : null,
            'parts_cost' => $this->parts_cost !== null ? (string) $this->parts_cost : null,
            'external_service_cost' => $this->external_service_cost !== null ? (string) $this->external_service_cost : null,
            'total_cost' => $this->total_cost !== null ? (string) $this->total_cost : null,
            'capitalize_as_upgrade' => (bool) $this->capitalize_as_upgrade,
            'condition_after_maintenance' => $this->condition_after_maintenance,
            'meter_reading_at_completion' => $this->meter_reading_at_completion !== null ? (string) $this->meter_reading_at_completion : null,
            'assigned_technician_id' => $this->assigned_technician_id,
            'journal_entry_id' => $this->journal_entry_id,
            'is_locked' => (bool) $this->is_locked,
            'problem_description' => $this->problem_description,
            'resolution_notes' => $this->resolution_notes,
        ];
    }
}
