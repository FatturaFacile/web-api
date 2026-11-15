<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockItem;

/**
 * A stock item of a cross-dock order with its inbound, received and outbound quantities.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cross_dock_order_id
 * @property string $stock_item_id
 * @property string $inbound_quantity
 * @property string $received_quantity
 * @property string $outbound_quantity
 */
final class WmsCrossDockLine extends BaseEnterpriseModel
{
    protected $table = 'wms_cross_dock_lines';

    protected function casts(): array
    {
        return [
            'inbound_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'outbound_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
