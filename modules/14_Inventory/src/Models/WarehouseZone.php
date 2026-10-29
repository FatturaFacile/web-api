<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseZone extends BaseEnterpriseModel
{
    protected $table = 'warehouse_zones';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function aisles(): HasMany
    {
        return $this->hasMany(WarehouseAisle::class, 'warehouse_zone_id');
    }
}
