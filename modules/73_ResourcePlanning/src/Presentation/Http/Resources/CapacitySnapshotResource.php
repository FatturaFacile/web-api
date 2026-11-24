<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceCapacitySnapshot;

/**
 * @mixin ResourceCapacitySnapshot
 */
final class CapacitySnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'week_start_date' => $this->week_start_date->format('Y-m-d'),
            'contractual_hours' => (float) $this->contractual_hours,
            'leave_and_holiday_hours' => (float) $this->leave_and_holiday_hours,
            'net_available_hours' => (float) $this->net_available_hours,
            'billable_booked_hours' => (float) $this->billable_booked_hours,
            'internal_booked_hours' => (float) $this->internal_booked_hours,
            'utilization_rate_percent' => (float) $this->utilization_rate_percent,
            'department_id' => $this->department_id,
            'cost_center_id' => $this->cost_center_id,
        ];
    }
}
