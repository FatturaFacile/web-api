<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaveManagement\Models\DepartmentStaffingThreshold;

/**
 * @mixin DepartmentStaffingThreshold
 */
final class DepartmentStaffingThresholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'min_present_count' => $this->min_present_count,
            'min_present_percent' => (float) $this->min_present_percent,
            'enforce_hard_block' => (bool) $this->enforce_hard_block,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
