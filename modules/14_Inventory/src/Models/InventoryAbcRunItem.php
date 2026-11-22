<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ranked item of an ABC run.
 */
final class InventoryAbcRunItem extends BaseEnterpriseModel
{
    protected $table = 'inventory_abc_run_items';

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'consumption_value' => 'decimal:2',
            'cumulative_share' => 'decimal:2',
            'is_manual_override' => 'boolean',
            'is_new_item' => 'boolean',
        ];
    }
}
