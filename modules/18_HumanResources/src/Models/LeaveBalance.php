<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeaveBalance extends BaseEnterpriseModel
{
    protected $table = 'hr_leave_balances';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'entitlement_hours' => 'decimal:2',
            'carryover_hours' => 'decimal:2',
            'accrued_hours' => 'decimal:2',
            'used_hours' => 'decimal:2',
            'pending_hours' => 'decimal:2',
            'available_hours' => 'decimal:2',
            'last_accrued_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
