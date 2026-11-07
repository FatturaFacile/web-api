<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The net sales expected of an agent or a team in a period.
 */
final class SalesTarget extends BaseEnterpriseModel
{
    protected $table = 'sales_targets';

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'target_amount' => 'decimal:2'];
    }
}
