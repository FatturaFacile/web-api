<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use App\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stock movement has just been stored.
 */
class StockMovementRecorded
{
    use Dispatchable;

    public function __construct(public readonly StockMovement $movement) {}

    /**
     * Whether the movement lowers the stock: an `out` movement or a negative adjustment. Movements of the single ledger
     * are unsigned, so a downward adjustment is one that leaves a bin without entering another.
     */
    public function isOutbound(): bool
    {
        return $this->movement->type === 'out' || ($this->movement->type === 'adjustment' && $this->movement->signed_quantity < 0);
    }
}
