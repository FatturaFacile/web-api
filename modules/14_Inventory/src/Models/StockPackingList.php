<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockPackingList extends BaseEnterpriseModel
{
    protected $table = 'stock_packing_lists';

    protected function casts(): array
    {
        return [
            'total_packages' => 'integer',
            'total_weight_kg' => 'decimal:3',
            'packed_at' => 'datetime',
            'net_weight_kg' => 'decimal:3',
            'total_volume_m3' => 'decimal:5',
            'cartons_count' => 'integer',
            'pallets_count' => 'integer',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(StockPackage::class, 'stock_packing_list_id');
    }

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(StockPickList::class, 'stock_pick_list_id');
    }
}
