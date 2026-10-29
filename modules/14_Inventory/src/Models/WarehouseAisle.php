<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseAisle extends BaseEnterpriseModel
{
    protected $table = 'warehouse_aisles';

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }

    public function racks(): HasMany
    {
        return $this->hasMany(WarehouseRack::class, 'warehouse_aisle_id');
    }
}
