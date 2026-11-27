<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

/**
 * The time-phased row of one warehouse and item of a DRP run.
 *
 * @property string $drp_run_id
 * @property string $warehouse_id
 * @property string $stock_item_id
 * @property string|null $source_warehouse_id
 * @property int $lead_time_days
 * @property string $opening_balance
 * @property string $safety_stock
 * @property list<array<string, mixed>> $cells
 */
final class DrpPlanLine extends BaseEnterpriseModel
{
    protected $table = 'distribution_drp_plan_lines';

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'opening_balance' => 'decimal:4',
            'safety_stock' => 'decimal:4',
            'cells' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
