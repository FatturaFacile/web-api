<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A publication of the plan to the employees, with what was acknowledged and why.
 */
final class ShiftPublication extends BaseEnterpriseModel
{
    protected $table = 'hr_shift_publications';

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'shift_count' => 'integer',
            'employee_count' => 'integer',
            'violations' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
