<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockPackageItem extends BaseEnterpriseModel
{
    protected $table = 'stock_package_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'net_weight_kg' => 'decimal:3',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(StockPackage::class, 'stock_package_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
