<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Feasibility tier of the CNC practical test (D.M. 21 March 2023), by debt / annual free cash flow ratio.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class CncPracticalTestTier extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'fin_cnc_practical_test_tiers';

    protected function casts(): array
    {
        return [
            'ratio_up_to' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
