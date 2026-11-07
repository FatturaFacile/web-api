<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * A position of a bid with its budget by cost type.
 *
 * @property string $id
 * @property string $bid_id
 * @property string $position_code
 * @property string $description
 * @property string $unit
 * @property string $quantity
 * @property string $unit_price
 * @property string $total
 * @property ?array<int, array<string, mixed>> $components
 * @property string $budget_labour_hours
 * @property string $budget_labour_amount
 * @property string $budget_material_amount
 * @property string $budget_machine_amount
 */
final class BidPosition extends TradesRecord
{
    protected $table = 'ct_bid_positions';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'total' => 'decimal:2', 'components' => 'array',
            'budget_labour_hours' => 'decimal:2', 'budget_labour_amount' => 'decimal:2', 'budget_material_amount' => 'decimal:2', 'budget_machine_amount' => 'decimal:2',
        ];
    }
}
