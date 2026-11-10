<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\SalaryCertificate;

/**
 * A Swiss salary certificate (form 11) of an employee and year.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin SalaryCertificate
 */
final class PayrollSalaryCertificateResource extends JsonResource
{
    use FormatsDateAttributes;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'year' => $this->year,
            'period_from' => $this->dateOnly('period_from'),
            'period_to' => $this->dateOnly('period_to'),
            'box1_salary' => $this->box1_salary,
            'box2_benefits' => $this->box2_benefits,
            'box3_irregular' => $this->box3_irregular,
            'box4_capital' => $this->box4_capital,
            'box7_other' => $this->box7_other,
            'box8_gross' => $this->box8_gross,
            'box9_social_contributions' => $this->box9_social_contributions,
            'box10_pension' => $this->box10_pension,
            'box11_net' => $this->box11_net,
            'box12_withholding_tax' => $this->box12_withholding_tax,
            'box13_expenses' => $this->box13_expenses,
            'remarks' => $this->remarks,
            'generated_at' => $this->generated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
