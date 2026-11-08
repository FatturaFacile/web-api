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
            'calendar' => $this->whenLoaded('shifts', fn () => $this->shifts->map(static fn (MfgWorkCenterShift $shift): array => ['weekday' => $shift->weekday, 'available_hours' => $shift->available_hours])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
