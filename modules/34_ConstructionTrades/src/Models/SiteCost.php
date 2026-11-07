<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * A cost of the site: worker hours, material or machines, optionally on a position of the bid.
 *
 * @property ?string $bid_position_id
 * @property string $cost_type
 * @property string $quantity
 * @property string $amount
 */
final class SiteCost extends TradesRecord
{
    /** @var list<string> */
    public const array Types = ['labour', 'material', 'machine', 'other'];

    protected $table = 'ct_site_costs';

    protected function casts(): array
    {
        return ['cost_date' => 'date', 'quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'amount' => 'decimal:2'];
    }
}
