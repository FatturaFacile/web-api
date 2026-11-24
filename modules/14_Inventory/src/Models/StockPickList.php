<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $completed_at
 * @property Carbon|null $shipped_at
 */
final class StockPickList extends BaseEnterpriseModel
{
    protected $table = 'stock_pick_lists';

    protected function casts(): array
    {
        return [
            'shortages' => 'array',
            'completed_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * The wave the list was released in, if any.
     *
     * @return BelongsTo<PickWave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(PickWave::class, 'pick_wave_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockPickLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockPickLine::class, 'stock_pick_list_id')->orderBy('sequence_number');
    }

    /**
     * @return HasMany<StockPackingList, $this>
     */
    public function packingLists(): HasMany
    {
        return $this->hasMany(StockPackingList::class, 'stock_pick_list_id');
    }

    public function originDocument(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'origin_document_type', 'origin_document_id');
    }
}
