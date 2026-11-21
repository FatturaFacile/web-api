<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;

/**
 * @property WorkplaceIncidentReport $resource
 */
final class IncidentReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $incident = $this->resource;

        return [
            'id' => $incident->id,
            'protocol_number' => $incident->protocol_number,
            'jurisdiction' => $incident->jurisdiction->value,
            'incident_date' => $incident->incident_date->toIso8601String(),
            'reported_at' => $incident->reported_at->toIso8601String(),
            'location_or_site_id' => $incident->location_or_site_id,
            'work_center_id' => $incident->work_center_id,
            'incident_type' => $incident->incident_type->value,
            'involved_employee_ids' => $incident->involved_employee_ids_json ?? [],
            'witness_employee_ids' => $incident->witness_employee_ids_json ?? [],
            'description' => $incident->description,
            'immediate_actions_taken' => $incident->immediate_actions_taken,
            'root_cause_analysis' => $incident->root_cause_analysis_json,
            'corrective_actions' => $incident->corrective_actions_json ?? [],
            'prognosis_days' => $incident->prognosis_days,
            'injury_dynamics' => $incident->injury_dynamics_json,
            'linked_insurance_claim_id' => $incident->linked_insurance_claim_id,
            'status' => $incident->status->value,
        ];
    }
}
