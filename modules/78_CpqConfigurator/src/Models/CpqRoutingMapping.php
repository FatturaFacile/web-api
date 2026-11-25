<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Maps the configuration to an operation of the dynamic routing: a work centre, the setup and run hours as formulas of
 * the configuration (e.g. `testing_hours + power_kw * 0.02`) and the hourly rate that prices them.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property array<string, string> $operation_name
 * @property string $work_center
 * @property string|null $condition_expression
 * @property string $setup_hours_formula
 * @property string $run_hours_formula
 * @property string $hourly_rate
 * @property int $sort_order
 * @property bool $is_active
 */
final class CpqRoutingMapping extends BaseEnterpriseModel
{
    protected $table = 'cpq_routing_mappings';

    protected function casts(): array
    {
        return [
            'operation_name' => 'array',
            'hourly_rate' => 'decimal:4',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
