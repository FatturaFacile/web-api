<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lot (or the lot-less stock, when `lot_id` is null) that left the source warehouse for a transfer order line, and
 * how much of it has been received at destination so far.
 */
final class TransferOrderLineLot extends BaseEnterpriseModel
{
    protected $table = 'transfer_order_line_lots';

    protected function casts(): array
    {
        return [
            'quantity_shipped' => 'decimal:4',
            'quantity_received' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<TransferOrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(TransferOrderLine::class, 'transfer_order_line_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
