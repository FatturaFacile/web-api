<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockPickLine extends BaseEnterpriseModel
{
    protected $table = 'stock_pick_lines';

    protected function casts(): array
    {
        return [
            'quantity_to_pick' => 'decimal:4',
            'quantity_picked' => 'decimal:4',
            'picked_at' => 'datetime',
            'sequence_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockPickList, $this>
     */
    public function pickList(): BelongsTo
    {
        return $this->belongsTo(StockPickList::class, 'stock_pick_list_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    /**
     * The packing or shipping bin the picked stock was moved to; null when it left the stock at the pick.
     *
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'destination_bin_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
