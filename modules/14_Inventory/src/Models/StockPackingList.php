<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockPackingList extends BaseEnterpriseModel
{
    protected $table = 'stock_packing_lists';

    protected function casts(): array
    {
        return [
            'total_packages' => 'integer',
            'total_weight_kg' => 'decimal:3',
            'packed_at' => 'datetime',
        ];
    }

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(StockPickList::class, 'stock_pick_list_id');
    }
}
