<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsAudit;

/**
 * An internal audit with its checklist and findings when loaded.
 *
 * @mixin QmsAudit
 */
final class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'scope' => $this->scope,
            'audit_type' => $this->audit_type,
            'planned_date' => $this->planned_date->toDateString(),
            'performed_date' => $this->performed_date?->toDateString(),
            'lead_auditor_id' => $this->lead_auditor_id,
            'auditee' => $this->auditee,
            'status' => $this->status,
            'summary' => $this->summary,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'checklist' => ChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'findings' => FindingResource::collection($this->whenLoaded('findings')),
        ];
    }
}
