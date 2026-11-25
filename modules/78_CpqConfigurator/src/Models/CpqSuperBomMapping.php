<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Maps the configuration to a line of the dynamic super-BOM: the component enters the BOM when the condition holds, with
 * the quantity of `quantity_formula` (e.g. `cable_length_m * 1.05`) and the unit cost of `unit_cost_formula` (or the
 * fixed `unit_cost`). `auto_include_message` explains an automatic inclusion to the sales representative.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property string $item_code
 * @property array<string, string> $item_name
 * @property string $uom
 * @property string|null $condition_expression
 * @property string $quantity_formula
 * @property string $unit_cost
 * @property string|null $unit_cost_formula
 * @property int $lead_time_days
 * @property array<string, string>|null $auto_include_message
 * @property int $sort_order
 * @property bool $is_active
 */
final class CpqSuperBomMapping extends BaseEnterpriseModel
{
    protected $table = 'cpq_superbom_mappings';

    protected function casts(): array
    {
        return [
            'item_name' => 'array',
            'unit_cost' => 'decimal:4',
            'lead_time_days' => 'integer',
            'auto_include_message' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
