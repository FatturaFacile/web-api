<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;

/**
 * The operational view of a visit: the judgment and its prescriptions. The clinical notes and the reference to the
 * vault are never part of it, whoever asks.
 *
 * @property OccupationalMedicalVisit $resource
 */
final class MedicalVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $visit = $this->resource;

        return [
            'id' => $visit->id,
            'employee_id' => $visit->employee_id,
            'doctor_name' => $visit->doctor_name,
            'visit_type' => $visit->visit_type->value,
            'visit_date' => $visit->visit_date->toDateString(),
            'next_visit_due_date' => $visit->next_visit_due_date?->toDateString(),
            'fitness_outcome' => $visit->fitness_outcome->value,
            'restrictions_summary' => $visit->restrictions_summary,
            'certificate_document_id' => $visit->certificate_document_id,
            'has_clinical_notes' => $visit->encrypted_clinical_notes_ref !== null,
        ];
    }
}
