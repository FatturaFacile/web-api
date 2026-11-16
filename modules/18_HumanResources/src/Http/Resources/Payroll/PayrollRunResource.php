<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * Money is returned as decimal CHF strings (exact, no floating point).
 *
 * @mixin PayrollRun
 */
final class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'run_number' => $this->run_number,
            'period' => $this->periodCode(),
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'payment_date' => $this->payment_date?->toDateString(),
            'status' => $this->status->value,
            'rate_set_id' => $this->rate_set_id,
            'currency' => $this->currency,
            'employee_ids' => $this->employee_ids,
            'inputs' => $this->inputs ?? [],
            'employee_count' => $this->employee_count,
            'totals' => [
                'gross' => Rappen::toDecimal($this->gross_rappen),
                'employee_deductions' => Rappen::toDecimal($this->employee_deductions_rappen),
                'employer_contributions' => Rappen::toDecimal($this->employer_contributions_rappen),
                'withholding_tax' => Rappen::toDecimal($this->withholding_tax_rappen),
                'net' => Rappen::toDecimal($this->net_rappen),
            ],
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by_user_id' => $this->closed_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
