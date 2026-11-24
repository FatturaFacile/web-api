<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity_on_hand
 * @property string $quantity_reserved
 */
final class StockQuant extends BaseEnterpriseModel
{
    protected $table = 'stock_quants';

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
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

    public function getQuantityAvailableAttribute(): float
    {
        return (float) max(0.0, ((float) $this->quantity_on_hand) - ((float) $this->quantity_reserved));
    }
}
