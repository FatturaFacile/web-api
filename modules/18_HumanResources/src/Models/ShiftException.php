<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A day that departs from the sequences: for one employee, a department, or the whole company. Without a shift type the
 * day stays free.
 */
final class ShiftException extends BaseEnterpriseModel
{
    protected $table = 'hr_shift_exceptions';

    protected function casts(): array
    {
        return ['exception_date' => 'date:Y-m-d'];
    }
}
