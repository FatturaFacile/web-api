<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

/**
 * Replenishment policy of one item in one warehouse (min-max, reorder point with fixed quantity, periodic review, demand-driven DRP).
 *
 * @property string $warehouse_id
 * @property string $stock_item_id
 * @property ReplenishmentPolicy $policy
 * @property string|null $source_warehouse_id
 * @property string $safety_stock
 * @property string $reorder_point
 * @property string $max_stock
 * @property string $fixed_quantity
 * @property string $min_transfer_qty
 * @property string $transfer_multiple
 * @property int $lead_time_days
 * @property int|null $review_cycle_days
 * @property int|null $target_days_of_supply
 * @property int $priority
 * @property bool $is_active
 */
final class ReplenishmentRule extends BaseEnterpriseModel
{
    protected $table = 'distribution_replenishment_rules';

    protected function casts(): array
    {
        return [
            'policy' => ReplenishmentPolicy::class,
            'safety_stock' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'max_stock' => 'decimal:4',
            'fixed_quantity' => 'decimal:4',
            'min_transfer_qty' => 'decimal:4',
            'transfer_multiple' => 'decimal:4',
            'lead_time_days' => 'integer',
            'review_cycle_days' => 'integer',
            'target_days_of_supply' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
