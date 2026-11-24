<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\LeaveAccrualPolicyFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a leave type accrues in a company: the yearly entitlement (from the contract, the collective agreement or the
 * law, entered by the company), monthly or yearly accrual, optional proration by FTE, a balance cap and a carry-over
 * limit. A policy with an employment type applies only to employees of that type and wins over a generic one.
 */
#[UseFactory(LeaveAccrualPolicyFactory::class)]
final class LeaveAccrualPolicy extends BaseEnterpriseModel
{
    public const string FrequencyMonthly = 'monthly';

    public const string FrequencyYearly = 'yearly';

    protected $table = 'hr_leave_accrual_policies';

    protected function casts(): array
    {
        return [
            'annual_entitlement_hours' => 'decimal:2',
            'prorate_by_fte' => 'boolean',
            'max_balance_hours' => 'decimal:2',
            'max_carryover_hours' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The leave type the policy accrues.
     *
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
