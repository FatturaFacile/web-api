<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMachineCenter;

/**
 * A machine of a work centre with its telemetry linkage. The telemetry endpoint key is a secret and is never returned.
 *
 * @mixin MfgMachineCenter
 */
final class MfgMachineCenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'machine_code' => $this->machine_code,
            'name' => $this->name,
            'work_center_id' => $this->work_center_id,
            'fixed_asset_id' => $this->asset_id,
            'maintenance_schedule_id' => $this->maintenance_schedule_id,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'is_industria_40_enabled' => $this->is_industria_40_enabled,
            'is_transizione_50_enabled' => $this->is_transizione_50_enabled,
            'telemetry_device_id' => $this->telemetry_device_id,
            'has_telemetry_endpoint_key' => $this->telemetry_endpoint_key !== null,
            'telemetry_status' => $this->telemetry_status,
            'telemetry_last_seen_at' => $this->telemetry_last_seen_at?->toIso8601String(),
            'ideal_cycle_time_seconds_per_unit' => $this->ideal_cycle_time_seconds_per_unit,
            'parallel_capacity_units' => $this->parallel_capacity_units,
            'efficiency_factor_percent' => $this->efficiency_factor_percent,
            'hourly_machine_run_cost_rate_override' => $this->hourly_machine_run_cost_rate_override,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
