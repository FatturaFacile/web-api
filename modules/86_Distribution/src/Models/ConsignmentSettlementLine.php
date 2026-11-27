<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockItem;

/**
 * @property string $settlement_id
 * @property string $stock_item_id
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_total
 */
final class ConsignmentSettlementLine extends BaseEnterpriseModel
{
    protected $table = 'distribution_consignment_settlement_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
