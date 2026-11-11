<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsNonconformity;

/**
 * A non-conformity with its source, severity, status and, when loaded, its actions.
 *
 * @mixin QmsNonconformity
 */
final class NonconformityResource extends JsonResource
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
            'description' => $this->description,
            'source' => $this->source,
            'severity' => $this->severity,
            'status' => $this->status,
            'detected_at' => $this->detected_at->toDateString(),
            'detected_by' => $this->detected_by,
            'owner_id' => $this->owner_id,
            'contact_id' => $this->contact_id,
            'mfg_non_conformance_id' => $this->mfg_non_conformance_id,
            'audit_finding_id' => $this->audit_finding_id,
            'containment_action' => $this->containment_action,
            'root_cause' => $this->root_cause,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'closure_notes' => $this->closure_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'capa_actions' => CapaResource::collection($this->whenLoaded('capaActions')),
        ];
    }
}
