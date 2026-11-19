<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Controlling record of the table cost_allocation_rules.
 *
 * @property string $source_cost_center_id
 * @property string $driver_type
 * @property int $step_order
 * @property list<array{type?: string, id?: string, weight?: float|int|string|null}> $recipients
 */
final class CostAllocationRule extends BaseEnterpriseModel
{
    protected $table = 'cost_allocation_rules';

    public const array Drivers = ['fixed_percentage', 'direct_revenue_share', 'direct_labor_hours', 'direct_cogs_share', 'machine_hours', 'headcount', 'square_meters'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'is_active' => 'boolean', 'step_order' => 'integer'];
    }
}
