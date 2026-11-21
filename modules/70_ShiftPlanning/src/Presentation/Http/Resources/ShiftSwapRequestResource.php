<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftSwapRequest;

/**
 * @mixin ShiftSwapRequest
 */
final class ShiftSwapRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requester_employee_id' => $this->requester_employee_id,
            'target_peer_employee_id' => $this->target_peer_employee_id,
            'is_broadcast' => $this->isBroadcast(),
            'shift_assignment_id' => $this->shift_assignment_id,
            'target_shift_assignment_id' => $this->target_shift_assignment_id,
            'status' => $this->status->value,
            'peer_responded_at' => $this->peer_responded_at?->toIso8601String(),
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'assignment' => $this->whenLoaded('assignment', fn (): array => ShiftAssignmentResource::make($this->assignment)->resolve($request)),
            'target_assignment' => $this->whenLoaded('targetAssignment', fn (): ?array => $this->targetAssignment === null ? null : ShiftAssignmentResource::make($this->targetAssignment)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
