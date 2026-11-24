<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovFinding;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A finding with its severity, recommendation and, on the single record, its remediation actions.
 *
 * @mixin GovFinding
 */
final class FindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $members = app(GovernanceMembers::class);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'audit_id' => $this->audit_id,
            'control_id' => $this->control_id,
            'risk_id' => $this->risk_id,
            'source' => $this->source,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'recommendation' => $this->recommendation,
            'owner_id' => $this->owner_id,
            'owner' => $members->reference($this->owner_id),
            'status' => $this->status,
            'accepted_reason' => $this->accepted_reason,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'accepted_by' => $members->reference($this->accepted_by),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $members->reference($this->closed_by),
            'closure_notes' => $this->closure_notes,
            'actions_count' => $this->whenCounted('actions'),
            'actions' => $this->whenLoaded('actions', fn () => ActionResource::collection($this->actions)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
