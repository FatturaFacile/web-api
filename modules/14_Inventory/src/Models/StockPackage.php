<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical package of a packing list: a box, a carton, or a pallet holding cartons.
 */
final class StockPackage extends BaseEnterpriseModel
{
    protected $table = 'stock_packages';

    protected function casts(): array
    {
        return [
            'net_weight_kg' => 'decimal:3',
            'tare_weight_kg' => 'decimal:3',
            'gross_weight_kg' => 'decimal:3',
        ];
    }

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(StockPackingList::class, 'stock_packing_list_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_package_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockPackageItem::class, 'stock_package_id');
    }
}
