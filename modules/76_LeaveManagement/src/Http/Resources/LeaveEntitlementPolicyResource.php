<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;

/**
 * @mixin LeaveEntitlementPolicy
 */
final class LeaveEntitlementPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'country' => $this->country,
            'leave_type' => $this->leave_type,
            'ccnl_or_contract_ref' => $this->ccnl_or_contract_ref,
            'unit_mode' => $this->unit_mode,
            'annual_entitlement' => (float) $this->annual_entitlement,
            'annual_entitlement_under_20' => $this->annual_entitlement_under_20 !== null ? (float) $this->annual_entitlement_under_20 : null,
            'annual_entitlement_over_50' => $this->annual_entitlement_over_50 !== null ? (float) $this->annual_entitlement_over_50 : null,
            'hours_per_workday' => (float) $this->hours_per_workday,
            'accrual_frequency' => $this->accrual_frequency,
            'prorate_by_fte' => (bool) $this->prorate_by_fte,
            'prorate_hire_termination' => (bool) $this->prorate_hire_termination,
            'min_days_worked_in_month_for_accrual' => $this->min_days_worked_in_month_for_accrual,
            'max_carry_over' => $this->max_carry_over !== null ? (float) $this->max_carry_over : null,
            'expiration_months' => $this->expiration_months,
            'inps_18_month_alert_enabled' => (bool) $this->inps_18_month_alert_enabled,
            'requires_approval' => (bool) $this->requires_approval,
            'requires_medical_certificate' => (bool) $this->requires_medical_certificate,
            'requires_inps_puc' => (bool) $this->requires_inps_puc,
            'waiting_period_days' => $this->waiting_period_days,
            'employer_indemnity_percent' => (float) $this->employer_indemnity_percent,
            'insurer_indemnity_percent' => (float) $this->insurer_indemnity_percent,
            'insurer_indemnity_percent_tier2' => $this->insurer_indemnity_percent_tier2 !== null ? (float) $this->insurer_indemnity_percent_tier2 : null,
            'comporto_max_days' => $this->comporto_max_days,
            'comporto_window_months' => $this->comporto_window_months,
            'ch_curtailment_grace_months' => $this->ch_curtailment_grace_months,
            'ch_curtailment_enabled' => (bool) $this->ch_curtailment_enabled,
            'legge_104_monthly_days_limit' => $this->legge_104_monthly_days_limit !== null ? (float) $this->legge_104_monthly_days_limit : null,
            'is_paid' => (bool) $this->is_paid,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
