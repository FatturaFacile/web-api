<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseBin extends BaseEnterpriseModel
{
    protected $table = 'warehouse_bins';

    protected function casts(): array
    {
        return [
            'max_weight_kg' => 'decimal:2',
            'max_volume_cbm' => 'decimal:4',
            'is_locked' => 'boolean',
        ];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(WarehouseRack::class, 'warehouse_rack_id');
    }

    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'warehouse_bin_id');
    }
}
