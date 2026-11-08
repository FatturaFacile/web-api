<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockItemCategory extends BaseEnterpriseModel
{
    protected $table = 'stock_item_categories';

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_category_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    /**
     * @return HasMany<StockItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class, 'category_id');
    }
}
