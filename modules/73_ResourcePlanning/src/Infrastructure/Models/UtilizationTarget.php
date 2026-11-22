<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The billable utilization a department or cost centre should reach; with neither it is the tenant default.
 *
 * @property string|null $department_id
 * @property string|null $cost_center_id
 * @property numeric-string $target_percent
 */
final class UtilizationTarget extends BaseEnterpriseModel
{
    protected $table = 'rp_utilization_targets';

    protected function casts(): array
    {
        return ['target_percent' => 'decimal:2'];
    }
}
