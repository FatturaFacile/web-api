<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An addressable storage location (zone > aisle > rack > bin). The bin type says what the location is for, and the
 * optional capacities (units, kilograms, cubic metres) bound what putaway may place in it; a capacity left empty is
 * not checked.
 */
final class WarehouseBin extends BaseEnterpriseModel
{
    /** What a location is for: putaway only targets storage, picking and bulk bins. */
    public const array BIN_TYPES = ['storage', 'picking', 'bulk', 'receiving', 'staging', 'shipping', 'quarantine', 'transit'];

    /** Bin types putaway may place stock into. */
    public const array PUTAWAY_BIN_TYPES = ['storage', 'picking', 'bulk'];

    protected $table = 'warehouse_bins';

    protected function casts(): array
    {
        return [
            'max_weight_kg' => 'decimal:2',
            'max_volume_cbm' => 'decimal:4',
            'max_quantity' => 'decimal:4',
            'is_locked' => 'boolean',
            'pick_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WarehouseRack, $this>
     */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(WarehouseRack::class, 'warehouse_rack_id');
    }

    /**
     * @return HasMany<StockQuant, $this>
     */
    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'warehouse_bin_id');
    }
}
