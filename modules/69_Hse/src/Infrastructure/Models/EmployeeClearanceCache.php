<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The operational clearance of an employee as the last physician judgment left it (outcome and prescriptions only).
 *
 * @property string $employee_id
 * @property string $medical_status
 * @property string|null $restrictions_summary
 * @property Carbon|null $valid_until
 * @property Carbon|null $computed_at
 */
final class EmployeeClearanceCache extends BaseEnterpriseModel
{
    protected $table = 'hse_employee_clearance_cache';

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'computed_at' => 'datetime'];
    }
}
