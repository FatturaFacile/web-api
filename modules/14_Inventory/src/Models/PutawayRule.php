<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where incoming stock of an item (or of a whole item category, or of every item) goes in a warehouse: a fixed bin,
 * any bin of a zone, or any bin of a type. Rules are tried by priority (lowest first); the strategy says how the bin is
 * chosen among the candidates: `fixed_location` (the target bin, or the candidates in pick order), `consolidate` (bins
 * that already hold the item first) or `empty_bin` (empty bins first). Locked bins and bins without the capacity for
 * the quantity are skipped.
 */
final class PutawayRule extends BaseEnterpriseModel
{
    public const array STRATEGIES = ['fixed_location', 'consolidate', 'empty_bin'];

    protected $table = 'putaway_rules';

    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<StockItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StockItemCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<WarehouseZone, $this>
     */
    public function targetZone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'target_zone_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function targetBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'target_bin_id');
    }
}
