<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $delivered_at
 */
final class Shipment extends BaseEnterpriseModel
{
    protected $table = 'shipments';

    /** @var list<string> */
    protected $hidden = ['label_content'];

    protected function casts(): array
    {
        return [
            'recipient' => 'array',
            'packages_count' => 'integer',
            'gross_weight_kg' => 'decimal:3',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShippingCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'shipping_carrier_id');
    }

    /**
     * @return BelongsTo<StockPackingList, $this>
     */
    public function packingList(): BelongsTo
    {
        return $this->belongsTo(StockPackingList::class, 'stock_packing_list_id');
    }

    /**
     * @return HasMany<ShipmentTrackingEvent, $this>
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class, 'shipment_id')->orderBy('occurred_at');
    }
}
