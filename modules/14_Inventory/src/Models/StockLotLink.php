<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a lot's genealogy: `lot_id` was made or packaged from `component_lot_id`.
 */
final class StockLotLink extends BaseEnterpriseModel
{
    protected $table = 'stock_lot_links';

    protected function casts(): array
    {
        return [
            'quantity_consumed' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function componentLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'component_lot_id');
    }
}
