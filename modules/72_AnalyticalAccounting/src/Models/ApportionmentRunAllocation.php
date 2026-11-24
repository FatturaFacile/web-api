<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Record of the table apportionment_run_allocations.
 */
final class ApportionmentRunAllocation extends BaseEnterpriseModel
{
    protected $table = 'apportionment_run_allocations';

    protected function casts(): array
    {
        return ['driver_value' => 'decimal:4', 'driver_total' => 'decimal:4', 'share_ratio' => 'decimal:8', 'allocated_amount' => 'decimal:4', 'rounding_adjustment' => 'decimal:4', 'step_order' => 'integer'];
    }
}
