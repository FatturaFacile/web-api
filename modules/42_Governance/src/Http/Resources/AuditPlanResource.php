<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovAuditPlan;
use Modules\Governance\Services\GovernanceMembers;

/**
 * The annual audit plan with the number of its audits and, on the single record, the audits themselves.
 *
 * @mixin GovAuditPlan
 */
final class AuditPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => app(GovernanceMembers::class)->reference($this->approved_by),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'audits_count' => $this->whenCounted('audits'),
            'audits' => $this->whenLoaded('audits', fn () => AuditResource::collection($this->audits)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
