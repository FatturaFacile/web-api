<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Controlling record of the table cost_allocation_runs.
 *
 * @property int $fiscal_year
 * @property int $fiscal_month
 * @property string $status
 */
final class CostAllocationRun extends BaseEnterpriseModel
{
    protected $table = 'cost_allocation_runs';

    public const string Draft = 'draft';

    public const string Posted = 'posted';

    public const string Reversed = 'reversed';

    protected function casts(): array
    {
        return ['executed_at' => 'datetime', 'reversed_at' => 'datetime'];
    }
}
