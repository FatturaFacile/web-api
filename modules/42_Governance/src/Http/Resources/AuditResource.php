<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Services\GovernanceMembers;

/**
 * An internal audit with scope, team, dates and the number of its findings.
 *
 * @mixin GovAudit
 */
final class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $members = app(GovernanceMembers::class);

        return [
            'id' => $this->id,
            'audit_plan_id' => $this->audit_plan_id,
            'code' => $this->code,
            'title' => $this->title,
            'audit_type' => $this->audit_type,
            'scope' => $this->scope,
            'objectives' => $this->objectives,
            'lead_auditor_id' => $this->lead_auditor_id,
            'lead_auditor' => $members->reference($this->lead_auditor_id),
            'team_member_ids' => $this->team_member_ids ?? [],
            'planned_start' => $this->planned_start->toDateString(),
            'planned_end' => $this->planned_end->toDateString(),
            'actual_start' => $this->actual_start?->toDateString(),
            'actual_end' => $this->actual_end?->toDateString(),
            'status' => $this->status,
            'conclusion' => $this->conclusion,
            'is_late_to_start' => $this->isLateToStart(),
            'findings_count' => $this->whenCounted('findings'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
