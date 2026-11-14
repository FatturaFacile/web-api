<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A remediation action with its owner, due date, overdue flag and the completion and verification trail.
 *
 * @mixin GovAction
 */
final class ActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $members = app(GovernanceMembers::class);

        return [
            'id' => $this->id,
            'finding_id' => $this->finding_id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'owner_id' => $this->owner_id,
            'owner' => $members->reference($this->owner_id),
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $members->reference($this->completed_by),
            'completion_notes' => $this->completion_notes,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $members->reference($this->verified_by),
            'verification_notes' => $this->verification_notes,
            'finding' => $this->whenLoaded('finding', fn () => ['id' => $this->finding->id, 'code' => $this->finding->code, 'title' => $this->finding->title, 'severity' => $this->finding->severity]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
