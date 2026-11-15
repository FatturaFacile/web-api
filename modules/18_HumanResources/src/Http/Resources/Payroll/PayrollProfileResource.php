<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\PayrollProfile;

/**
 * The AHV number is never returned in clear text.
 *
 * @mixin PayrollProfile
 */
final class PayrollProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'ahv_number_masked' => $this->maskedAhvNumber(),
            'has_ahv_number' => $this->ahv_number !== null && $this->ahv_number !== '',
            'work_canton' => $this->work_canton,
            'residence_canton' => $this->residence_canton,
            'residence_country' => $this->residence_country,
            'residence_municipality_bfs' => $this->residence_municipality_bfs,
            'permit_type' => $this->permit_type,
            'civil_status' => $this->civil_status,
            'children_count' => $this->children_count,
            'church_tax' => $this->church_tax,
            'qst_tariff_code' => $this->qst_tariff_code,
            'qst_canton' => $this->qst_canton,
            'is_subject_to_withholding_tax' => $this->isSubjectToWithholdingTax(),
            'bvg_insured' => $this->bvg_insured,
            'bvg_plan_code' => $this->bvg_plan_code,
            'uvg_class' => $this->uvg_class,
            'ktg_insured' => $this->ktg_insured,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
