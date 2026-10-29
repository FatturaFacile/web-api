<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class StockPickList extends BaseEnterpriseModel
{
    protected $table = 'stock_pick_lists';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockPickLine::class, 'stock_pick_list_id');
    }

    public function packingLists(): HasMany
    {
        return $this->hasMany(StockPackingList::class, 'stock_pick_list_id');
    }

    public function originDocument(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'origin_document_type', 'origin_document_id');
    }
}
