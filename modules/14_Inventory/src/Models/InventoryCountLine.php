<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryCountLine extends BaseEnterpriseModel
{
    protected $table = 'inventory_count_lines';

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'discrepancy_quantity' => 'decimal:4',
            'discrepancy_valuation' => 'decimal:2',
            'counted_at' => 'datetime',
            'first_counted_quantity' => 'decimal:4',
            'recount_count' => 'integer',
            'recount_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventoryCountSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(InventoryCountSession::class, 'inventory_count_session_id');
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
}
