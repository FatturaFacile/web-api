<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LeaveType extends BaseEnterpriseModel
{
    protected $table = 'hr_leave_types';

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean',
            'requires_attachment' => 'boolean',
            'annual_entitlement_hours' => 'decimal:2',
            'max_carryover_hours' => 'decimal:2',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'leave_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
    }
}
