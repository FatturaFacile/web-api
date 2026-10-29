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
        ];
    }

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(StockPickList::class, 'stock_pick_list_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
