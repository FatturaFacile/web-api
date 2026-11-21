<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `fact_operational_efficiency` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class FactOperationalEfficiency extends BaseEnterpriseModel
{
    protected $table = 'fact_operational_efficiency';

    protected function casts(): array
    {
        return ['work_date' => 'date', 'billable_hours' => 'decimal:4', 'non_billable_hours' => 'decimal:4'];
    }
}
