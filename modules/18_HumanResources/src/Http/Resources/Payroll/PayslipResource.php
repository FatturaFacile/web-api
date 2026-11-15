<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * @mixin Payslip
 */
final class PayslipResource extends JsonResource
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
            'employee_number' => $this->snapshot['employee_number'] ?? null,
            'full_name' => $this->snapshot['full_name'] ?? null,
            'rate_set_id' => $this->rate_set_id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'days_worked' => $this->days_worked,
            'fte_percentage' => (string) $this->fte_percentage,
            'currency' => 'CHF',
            'gross' => Rappen::toDecimal($this->gross_rappen),
            'bases' => [
                'ahv' => Rappen::toDecimal($this->ahv_base_rappen),
                'alv' => Rappen::toDecimal($this->alv_base_rappen),
                'alv_solidarity' => Rappen::toDecimal($this->alv_solidarity_base_rappen),
                'bvg_insured_salary_annual' => Rappen::toDecimal($this->bvg_insured_salary_rappen),
                'uvg' => Rappen::toDecimal($this->uvg_base_rappen),
                'withholding_tax' => Rappen::toDecimal($this->qst_base_rappen),
            ],
            'withholding_tax' => [
                'canton' => $this->qst_canton,
                'tariff_code' => $this->qst_tariff_code,
                'amount' => Rappen::toDecimal($this->withholding_tax_rappen),
            ],
            'employee_deductions' => Rappen::toDecimal($this->employee_deductions_rappen),
            'employer_contributions' => Rappen::toDecimal($this->employer_contributions_rappen),
            'net' => Rappen::toDecimal($this->net_rappen),
            'lines' => PayslipLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
