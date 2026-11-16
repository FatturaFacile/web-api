<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Enums\ApprovalStatus;
use Modules\Documents\Models\DmsApprovalRequest;

/**
 * API representation of a document approval request with its current step and decisions.
 *
 * @mixin DmsApprovalRequest
 */
final class ApprovalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $step = $this->status === ApprovalStatus::Pending && $this->relationLoaded('template')
            ? $this->template?->steps->firstWhere('position', $this->current_step)
            : null;

        return [
            'id' => (string) $this->id,
            'document_id' => (string) $this->document_id,
            'document_title' => $this->whenLoaded('document', fn (): ?string => $this->document?->title),
            'document_version_id' => $this->document_version_id,
            'template_id' => $this->template_id,
            'template_name' => $this->template_name,
            'status' => $this->status->value,
            'current_step' => $this->current_step,
            'total_steps' => $this->total_steps,
            'current_step_detail' => $step === null ? null : [
                'position' => $step->position,
                'name' => $step->name,
                'approver_type' => $step->approver_type,
                'approver_user_id' => $step->approver_user_id,
                'approver_role' => $step->approver_role,
            ],
            'requested_by' => $this->requested_by,
            'note' => $this->note,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'decisions' => $this->whenLoaded('decisions', fn (): array => $this->decisions->map(static fn ($decision): array => [
                'step_position' => $decision->step_position,
                'step_name' => $decision->step_name,
                'decided_by' => $decision->decided_by,
                'decision' => $decision->decision,
                'comment' => $decision->comment,
                'decided_at' => $decision->decided_at->toIso8601String(),
            ])->all()),
        ];
    }
}
