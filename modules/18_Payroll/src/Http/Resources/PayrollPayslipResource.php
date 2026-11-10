<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\Payslip;

/**
 * A payslip of an employee for a month; `lines` only when loaded (detail).
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin Payslip
 */
final class PayrollPayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'payroll_run_id' => $this->payroll_run_id,
            'employee_id' => $this->employee_id,
            'year' => $this->year,
            'month' => $this->month,
            'employee_name' => $this->employee_name,
            'paid_days' => $this->paid_days,
            'gross_salary' => $this->gross_salary,
            'ahv_basis' => $this->ahv_basis,
            'alv_basis' => $this->alv_basis,
            'uvg_basis' => $this->uvg_basis,
            'ktg_basis' => $this->ktg_basis,
            'bvg_insured_salary' => $this->bvg_insured_salary,
            'withholding_tax_basis' => $this->withholding_tax_basis,
            'withholding_tax_rate_percent' => $this->withholding_tax_rate_percent,
            'withholding_tax_code' => $this->withholding_tax_code,
            'withholding_tax_canton' => $this->withholding_tax_canton,
            'employee_deductions' => $this->employee_deductions,
            'net_salary' => $this->net_salary,
            'payout_amount' => $this->payout_amount,
            'employer_contributions' => $this->employer_contributions,
            'warnings' => $this->warnings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
            'lines' => PayrollPayslipLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
