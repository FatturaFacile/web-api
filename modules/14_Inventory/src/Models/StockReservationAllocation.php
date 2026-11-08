<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of one quant a reservation holds: it records which lot and bin were chosen, so a release gives back
 * exactly those units.
 */
final class StockReservationAllocation extends BaseEnterpriseModel
{
    protected $table = 'stock_reservation_allocations';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    /**
     * @return BelongsTo<StockQuant, $this>
     */
    public function quant(): BelongsTo
    {
        return $this->belongsTo(StockQuant::class, 'stock_quant_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
