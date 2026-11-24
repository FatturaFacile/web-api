<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'incident_number' => $this->incident_number,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'impact' => $this->impact,
            'urgency' => $this->urgency,
            'status' => $this->status,
            'service_category_id' => $this->service_category_id,
            'affected_asset_id' => $this->affected_asset_id,
            'reported_by_id' => $this->reported_by_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'is_major_incident' => (bool) $this->is_major_incident,
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
