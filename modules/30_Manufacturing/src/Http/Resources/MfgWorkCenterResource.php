<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterShift;

/**
 * A work centre with its capacity, rates and weekly calendar (when loaded). Rates and hours are decimal strings.
 *
 * @mixin MfgWorkCenter
 */
final class MfgWorkCenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center_code' => $this->work_center_code,
            'name' => $this->name,
            'description' => $this->description,
            'department_code' => $this->department_code,
            'capacity_hours_per_day' => $this->capacity_hours_per_day,
            'efficiency_rate' => $this->efficiency_rate,
            'availability_rate' => $this->availability_rate,
            'hourly_cost_rate' => $this->hourly_cost_rate,
            'hourly_overhead_rate' => $this->hourly_overhead_rate,
            'status' => $this->status,
            'efficiency_factor_percent' => $this->efficiency_rate,
            'hourly_labor_cost_rate' => $this->hourly_cost_rate,
            'warehouse_id' => $this->warehouse_id,
            'cost_center_id' => $this->cost_center_id,
            'working_calendar_id' => $this->working_calendar_id,
            'parent_work_center_id' => $this->parent_work_center_id,
            'parallel_capacity_units' => $this->parallel_capacity_units,
            'oee_target_percent' => $this->oee_target_percent,
            'availability_target_percent' => $this->availability_target_percent,
            'performance_target_percent' => $this->performance_target_percent,
            'quality_target_percent' => $this->quality_target_percent,
            'hourly_setup_cost_rate' => $this->hourly_setup_cost_rate,
            'hourly_machine_run_cost_rate' => $this->hourly_machine_run_cost_rate,
            'currency_code' => $this->currency_code,
            'default_queue_wait_minutes' => $this->default_queue_wait_minutes,
            'default_move_time_minutes' => $this->default_move_time_minutes,
            'alternate_work_center_ids' => $this->alternate_work_center_ids ?? [],
            'calendar' => $this->whenLoaded('shifts', fn () => $this->shifts->map(static fn (MfgWorkCenterShift $shift): array => ['weekday' => $shift->weekday, 'available_hours' => $shift->available_hours])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
