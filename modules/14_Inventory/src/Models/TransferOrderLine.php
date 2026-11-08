<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One item of a transfer order: the requested quantity (optionally of one lot), what left the source warehouse and
 * what reached the destination so far. The lots that were shipped are kept per line (TransferOrderLineLot), so the
 * receipt puts back exactly the lots that travelled.
 */
final class TransferOrderLine extends BaseEnterpriseModel
{
    protected $table = 'transfer_order_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_shipped' => 'decimal:4',
            'quantity_received' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<TransferOrder, $this>
     */
    public function transferOrder(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class, 'transfer_order_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * @return HasMany<TransferOrderLineLot, $this>
     */
    public function shippedLots(): HasMany
    {
        return $this->hasMany(TransferOrderLineLot::class, 'transfer_order_line_id')->orderBy('id');
    }
}
