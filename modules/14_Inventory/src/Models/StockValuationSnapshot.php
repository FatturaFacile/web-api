<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockValuationSnapshot extends BaseEnterpriseModel
{
    protected $table = 'stock_valuation_snapshots';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'quantity_on_hand' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_valuation' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
