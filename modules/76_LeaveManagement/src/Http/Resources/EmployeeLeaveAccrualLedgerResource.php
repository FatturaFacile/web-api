<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;

/**
 * @mixin EmployeeLeaveAccrualLedger
 */
final class EmployeeLeaveAccrualLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'employee_number' => $this->whenLoaded('employee', fn () => $this->employee?->employee_number),
            'policy_id' => $this->policy_id,
            'fiscal_year' => $this->fiscal_year,
            'country' => $this->country,
            'leave_type' => $this->leave_type,
            'unit_mode' => $this->unit_mode,
            'prior_year_carry_over' => (float) $this->prior_year_carry_over,
            'accrued_ytd' => (float) $this->accrued_ytd,
            'curtailment_reduction_ytd' => (float) $this->curtailment_reduction_ytd,
            'taken_ytd' => (float) $this->taken_ytd,
            'planned_approved_future' => (float) $this->planned_approved_future,
            'pending_approval_amount' => (float) $this->pending_approval_amount,
            'projected_year_end_balance' => (float) $this->projected_year_end_balance,
            'annual_entitlement_target' => (float) $this->annual_entitlement_target,
            'expiration_deadline_date' => $this->expiration_deadline_date?->format('Y-m-d'),
            'expiring_carry_over_amount' => (float) $this->expiring_carry_over_amount,
            'deadline_alert_level' => $this->deadline_alert_level,
            'last_accrued_period' => $this->last_accrued_period,
            'accrual_journal' => $this->accrual_journal ?? [],
        ];
    }
}
