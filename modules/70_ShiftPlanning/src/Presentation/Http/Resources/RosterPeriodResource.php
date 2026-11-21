<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;

/**
 * @mixin RosterSchedulePeriod
 */
final class RosterPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_or_department_id' => $this->location_or_department_id,
            'production_line_id' => $this->production_line_id,
            'period_type' => $this->period_type->value,
            'jurisdiction' => $this->jurisdiction->value,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
        ];
    }
}
