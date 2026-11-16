<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityServiceRequest;

/**
 * A service request with its SLA state, people, location and link to the EAM work order.
 *
 * @mixin FacilityServiceRequest
 */
final class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'building_id' => $this->building_id,
            'space_id' => $this->space_id,
            'requester_id' => $this->requester_id,
            'assignee_id' => $this->assignee_id,
            'requester' => $this->whenLoaded('requester', fn (): ?array => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee === null ? null : ['id' => $this->assignee->id, 'name' => $this->assignee->name]),
            'asset_id' => $this->asset_id,
            'work_order_id' => $this->work_order_id,
            'inspection_id' => $this->inspection_id,
            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'hold_reason' => $this->hold_reason,
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
