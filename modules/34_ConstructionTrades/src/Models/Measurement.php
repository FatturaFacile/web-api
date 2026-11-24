<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * A quantity measured on site for a position: the settlement adds them up.
 *
 * @property string $bid_position_id
 * @property string $quantity
 */
final class Measurement extends TradesRecord
{
    protected $table = 'ct_measurements';

    protected function casts(): array
    {
        return ['measured_on' => 'date', 'quantity' => 'decimal:4'];
    }
}
