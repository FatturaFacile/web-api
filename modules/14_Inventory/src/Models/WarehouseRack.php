<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseRack extends BaseEnterpriseModel
{
    protected $table = 'warehouse_racks';

    /**
     * @return BelongsTo<WarehouseAisle, $this>
     */
    public function aisle(): BelongsTo
    {
        return $this->belongsTo(WarehouseAisle::class, 'warehouse_aisle_id');
    }

    /**
     * @return HasMany<WarehouseBin, $this>
     */
    public function bins(): HasMany
    {
        return $this->hasMany(WarehouseBin::class, 'warehouse_rack_id');
    }
}
