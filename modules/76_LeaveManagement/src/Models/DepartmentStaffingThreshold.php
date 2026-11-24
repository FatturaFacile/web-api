<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Department;

/**
 * Minimum staffing threshold (presidio minimo) configured per department.
 *
 * @property string $department_id
 * @property int $min_present_count
 * @property string $min_present_percent
 * @property bool $enforce_hard_block
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string|null $notes
 * @property bool $is_active
 */
final class DepartmentStaffingThreshold extends BaseEnterpriseModel
{
    protected $table = 'lm_department_staffing_thresholds';

    protected function casts(): array
    {
        return [
            'min_present_count' => 'integer',
            'min_present_percent' => 'decimal:2',
            'enforce_hard_block' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
