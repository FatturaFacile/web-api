<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A picking wave: several sales orders of one warehouse released together, one pick list per order, staged in the same
 * shipping bin. The wave is completed when every pick list is shipped or cancelled.
 *
 * @property Carbon|null $released_at
 * @property Carbon|null $completed_at
 */
final class PickWave extends BaseEnterpriseModel
{
    public const array STATUSES = ['released', 'completed', 'cancelled'];

    protected $table = 'pick_waves';

    protected function casts(): array
    {
        return [
            'shortages' => 'array',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function stagingBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'staging_bin_id');
    }

    /**
     * @return HasMany<StockPickList, $this>
     */
    public function pickLists(): HasMany
    {
        return $this->hasMany(StockPickList::class, 'pick_wave_id');
    }
}
