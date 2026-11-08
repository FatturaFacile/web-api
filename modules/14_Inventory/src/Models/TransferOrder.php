<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A transfer of stock from one warehouse to another. Shipping moves the stock out of the source bins into the transit
 * bin of the destination warehouse (stock in transit: still owned and valued, not available to orders); receiving
 * moves it from transit into the destination bins, in one or more receipts.
 *
 * Statuses: draft -> in_transit -> partially_received -> received; only a draft can be cancelled.
 *
 * @property Carbon|null $requested_date
 * @property Carbon|null $shipped_at
 * @property Carbon|null $received_at
 */
final class TransferOrder extends BaseEnterpriseModel
{
    public const array STATUSES = ['draft', 'in_transit', 'partially_received', 'received', 'cancelled'];

    protected $table = 'transfer_orders';

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * @return BelongsTo<WarehouseBin, $this>
     */
    public function transitBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'transit_bin_id');
    }

    /**
     * @return HasMany<TransferOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TransferOrderLine::class, 'transfer_order_id')->orderBy('id');
    }
}
