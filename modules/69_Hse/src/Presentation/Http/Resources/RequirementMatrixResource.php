<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;

/**
 * @property SafetyRoleRequirementMatrix $resource
 */
final class RequirementMatrixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $matrix = $this->resource;

        return [
            'id' => $matrix->id,
            'name' => $matrix->name,
            'jurisdiction' => $matrix->jurisdiction->value,
            'job_position_id' => $matrix->job_position_id,
            'work_center_id' => $matrix->work_center_id,
            'construction_site_id' => $matrix->construction_site_id,
            'suva_hazard_class' => $matrix->suva_hazard_class,
            'dvr_risk_profile_code' => $matrix->dvr_risk_profile_code,
            'required_training_types' => $matrix->required_training_types_json ?? [],
            'required_medical_exam_codes' => $matrix->required_medical_exam_codes_json ?? [],
            'required_ppe_product_ids' => $matrix->required_ppe_product_ids_json ?? [],
            'is_active' => $matrix->is_active,
            'version' => $matrix->version,
        ];
    }
}
