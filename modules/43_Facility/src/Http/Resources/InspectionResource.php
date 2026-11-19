<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityInspection;

/**
 * One inspection with its checklist and answers.
 *
 * @mixin FacilityInspection
 */
final class InspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'number' => $this->number,
            'title' => $this->title,
            'building_id' => $this->building_id,
            'space_id' => $this->space_id,
            'assignee_id' => $this->assignee_id,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'result' => $this->result,
            'checklist' => $this->checklist,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->completed_by,
            'notes' => $this->notes,
            'service_request_id' => $this->service_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
