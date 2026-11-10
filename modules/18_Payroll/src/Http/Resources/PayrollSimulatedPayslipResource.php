<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Calculation\PayrollResult;

/**
 * A payslip computed by the engine and not saved (simulation): the amounts are decimal strings and there are no ids,
 * the lines come in the order of the engine.
 *
 * @mixin PayrollResult
 */
final class PayrollSimulatedPayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->payslip->employeeId,
            'employee_name' => $this->payslip->employeeName,
            'year' => $this->payslip->year,
            'month' => $this->payslip->month,
            'paid_days' => $this->payslip->paidDays,
            'gross_salary' => $this->payslip->grossSalary,
            'ahv_basis' => $this->payslip->ahvBasis,
            'alv_basis' => $this->payslip->alvBasis,
            'uvg_basis' => $this->payslip->uvgBasis,
            'ktg_basis' => $this->payslip->ktgBasis,
            'bvg_insured_salary' => $this->payslip->bvgInsuredSalary,
            'withholding_tax_basis' => $this->payslip->withholdingTaxBasis,
            'withholding_tax_rate_percent' => $this->payslip->withholdingTaxRatePercent,
            'withholding_tax_code' => $this->payslip->withholdingTaxCode,
            'withholding_tax_canton' => $this->payslip->withholdingTaxCanton,
            'employee_deductions' => $this->payslip->employeeDeductions,
            'net_salary' => $this->payslip->netSalary,
            'payout_amount' => $this->payslip->payoutAmount,
            'employer_contributions' => $this->payslip->employerContributions,
            'warnings' => $this->payslip->warnings,
            'lines' => array_map(static fn ($line): array => [
                'wage_type_code' => $line->wageTypeCode,
                'description' => $line->description,
                'kind' => $line->kind,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
            ], $this->lines),
        ];
    }
}
