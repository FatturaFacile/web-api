<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A step of a work cycle, per unit of the cycle times its quantity factor.
 *
 * @property ?string $price_item_id
 * @property array<string, string> $descriptions
 * @property string $quantity_factor
 * @property string $unit_price
 * @property string $labour_minutes
 * @property string $material_cost
 * @property string $machine_cost
 */
final class WorkCycleStep extends TradesRecord
{
    use AllowsGlobalRecords;

    protected $table = 'ct_work_cycle_steps';

    protected function casts(): array
    {
        return ['descriptions' => 'array', 'quantity_factor' => 'decimal:4', 'unit_price' => 'decimal:4', 'labour_minutes' => 'decimal:2', 'material_cost' => 'decimal:4', 'machine_cost' => 'decimal:4'];
    }
}
