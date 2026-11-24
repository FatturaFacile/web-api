<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entitlement, accrual, carry-over, waiting-period, comporto and Art. 329a/329b CO policy.
 *
 * @property string $code
 * @property string $name
 * @property string $country
 * @property string $leave_type
 * @property string|null $ccnl_or_contract_ref
 * @property string $unit_mode
 * @property string $annual_entitlement
 * @property string|null $annual_entitlement_under_20
 * @property string|null $annual_entitlement_over_50
 * @property string $hours_per_workday
 * @property string $accrual_frequency
 * @property bool $prorate_by_fte
 * @property bool $prorate_hire_termination
 * @property int $min_days_worked_in_month_for_accrual
 * @property string|null $max_carry_over
 * @property int|null $expiration_months
 * @property bool $inps_18_month_alert_enabled
 * @property bool $requires_approval
 * @property bool $requires_medical_certificate
 * @property bool $requires_inps_puc
 * @property int $waiting_period_days
 * @property string $employer_indemnity_percent
 * @property string $insurer_indemnity_percent
 * @property string|null $insurer_indemnity_percent_tier2
 * @property int|null $comporto_max_days
 * @property int $comporto_window_months
 * @property int $ch_curtailment_grace_months
 * @property bool $ch_curtailment_enabled
 * @property string|null $legge_104_monthly_days_limit
 * @property bool $is_paid
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 */
final class LeaveEntitlementPolicy extends BaseEnterpriseModel
{
    protected $table = 'lm_leave_entitlement_policies';

    protected function casts(): array
    {
        return [
            'annual_entitlement' => 'decimal:2',
            'annual_entitlement_under_20' => 'decimal:2',
            'annual_entitlement_over_50' => 'decimal:2',
            'hours_per_workday' => 'decimal:2',
            'prorate_by_fte' => 'boolean',
            'prorate_hire_termination' => 'boolean',
            'min_days_worked_in_month_for_accrual' => 'integer',
            'max_carry_over' => 'decimal:2',
            'expiration_months' => 'integer',
            'inps_18_month_alert_enabled' => 'boolean',
            'requires_approval' => 'boolean',
            'requires_medical_certificate' => 'boolean',
            'requires_inps_puc' => 'boolean',
            'waiting_period_days' => 'integer',
            'employer_indemnity_percent' => 'decimal:2',
            'insurer_indemnity_percent' => 'decimal:2',
            'insurer_indemnity_percent_tier2' => 'decimal:2',
            'comporto_max_days' => 'integer',
            'comporto_window_months' => 'integer',
            'ch_curtailment_grace_months' => 'integer',
            'ch_curtailment_enabled' => 'boolean',
            'legge_104_monthly_days_limit' => 'decimal:2',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<EmployeeLeaveAccrualLedger, $this>
     */
    public function ledgers(): HasMany
    {
        return $this->hasMany(EmployeeLeaveAccrualLedger::class, 'policy_id');
    }
}
