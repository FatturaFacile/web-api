<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity a sale took beyond the stock of a warehouse. Quants cannot go below zero, but the till and the delivery notes
 * never refuse a sale for missing stock: the missing part is covered by a `negative_stock_cover` movement, so the sale
 * is booked in full, and stays open here until stock comes in and settles it. Stock levels subtract the open quantity,
 * which is how a sale beyond the stock still shows as negative stock.
 */
final class StockShortfall extends BaseEnterpriseModel
{
    protected $table = 'stock_shortfalls';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_open' => 'decimal:4',
            'settled_at' => 'datetime',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
