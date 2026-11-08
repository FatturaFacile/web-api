<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockSerialNumber extends BaseEnterpriseModel
{
    protected $table = 'stock_serial_numbers';

    protected function casts(): array
    {
        return [
            'warranty_start_date' => 'date',
            'warranty_end_date' => 'date',
            'purchase_date' => 'date',
            'purchase_value' => 'decimal:2',
        ];
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
        return $this->belongsTo(WarehouseBin::class, 'current_warehouse_bin_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
