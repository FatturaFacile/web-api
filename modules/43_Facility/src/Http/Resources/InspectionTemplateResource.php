<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityInspectionTemplate;

/**
 * A recurring inspection with its checklist.
 *
 * @mixin FacilityInspectionTemplate
 */
final class InspectionTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'building_id' => $this->building_id,
            'space_id' => $this->space_id,
            'frequency' => $this->frequency,
            'interval_count' => $this->interval_count,
            'checklist' => $this->checklist,
            'assignee_id' => $this->assignee_id,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'lead_days' => $this->lead_days,
            'last_generated_on' => $this->last_generated_on?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
