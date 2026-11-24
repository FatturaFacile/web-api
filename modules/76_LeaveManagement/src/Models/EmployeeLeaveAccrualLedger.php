<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

/**
 * Employee accrual ledger for a fiscal year and canonical leave type.
 * Tracks separate counters for IT (Ferie, ROL, Ex-Festività) and CH (Ferien Art. 329a/329b CO).
 *
 * @property string $employee_id
 * @property string|null $policy_id
 * @property int $fiscal_year
 * @property string $country
 * @property string $leave_type
 * @property string $unit_mode
 * @property string $prior_year_carry_over
 * @property string $accrued_ytd
 * @property string $curtailment_reduction_ytd
 * @property string $taken_ytd
 * @property string $planned_approved_future
 * @property string $pending_approval_amount
 * @property string $projected_year_end_balance
 * @property string $annual_entitlement_target
 * @property CarbonImmutable|null $expiration_deadline_date
 * @property string $expiring_carry_over_amount
 * @property string $deadline_alert_level
 * @property string|null $last_accrued_period
 * @property array<string, mixed>|null $accrual_journal
 * @property array<string, mixed>|null $metadata
 */
final class EmployeeLeaveAccrualLedger extends BaseEnterpriseModel
{
    protected $table = 'lm_employee_leave_accrual_ledgers';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'prior_year_carry_over' => 'decimal:2',
            'accrued_ytd' => 'decimal:2',
            'curtailment_reduction_ytd' => 'decimal:2',
            'taken_ytd' => 'decimal:2',
            'planned_approved_future' => 'decimal:2',
            'pending_approval_amount' => 'decimal:2',
            'projected_year_end_balance' => 'decimal:2',
            'annual_entitlement_target' => 'decimal:2',
            'expiration_deadline_date' => 'immutable_date',
            'expiring_carry_over_amount' => 'decimal:2',
            'accrual_journal' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<LeaveEntitlementPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPolicy::class, 'policy_id');
    }
}
