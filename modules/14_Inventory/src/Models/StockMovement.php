<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $executed_at
 */
final class StockMovement extends BaseEnterpriseModel
{
    protected $table = 'stock_movements';

    /**
     * Calculated grid columns as portable SQL: the short movement number and the signed quantity the movements grid
     * shows (a movement that only leaves a location is negative).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            // The movements table has no number column: the grid and the API show the first 8 characters of the id.
            'movement_number' => 'SUBSTR(CAST(stock_movements.id AS VARCHAR(36)), 1, 8)',
            'signed_quantity' => 'CASE WHEN stock_movements.source_location_id IS NOT NULL AND stock_movements.destination_location_id IS NULL THEN -ABS(stock_movements.quantity) ELSE ABS(stock_movements.quantity) END',
        ];
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_valuation' => 'decimal:2',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function sourceBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'source_location_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'destination_location_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<StockSerialNumber, $this>
     */
    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(StockSerialNumber::class, 'serial_number_id');
    }

    public function originDocument(): MorphTo
    {
        return $this->morphTo();
    }
}
