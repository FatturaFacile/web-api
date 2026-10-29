<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class StockReservation extends BaseEnterpriseModel
{
    protected $table = 'stock_reservations';

    protected function casts(): array
    {
        return [
            'quantity_reserved' => 'decimal:4',
            'expires_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function reservedFor(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reserved_for_type', 'reserved_for_id');
    }
}
