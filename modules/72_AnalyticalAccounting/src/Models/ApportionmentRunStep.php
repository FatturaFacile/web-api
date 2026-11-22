<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Record of the table apportionment_run_steps.
 */
final class ApportionmentRunStep extends BaseEnterpriseModel
{
    protected $table = 'apportionment_run_steps';

    protected function casts(): array
    {
        return ['warnings' => 'array', 'pool_amount' => 'decimal:4', 'allocated_amount' => 'decimal:4', 'driver_total' => 'decimal:4', 'step_order' => 'integer'];
    }
}
