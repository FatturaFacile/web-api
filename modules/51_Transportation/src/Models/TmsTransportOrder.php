<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shipment to move from an origin to a destination within pickup and delivery windows, with the carrier and expected cost once assigned.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string|null $reference
 * @property string $status
 * @property string $origin_name
 * @property string $origin_location
 * @property string|null $origin_city
 * @property string|null $origin_zone
 * @property string $destination_name
 * @property string $destination_location
 * @property string|null $destination_city
 * @property string|null $destination_zone
 * @property Carbon|null $pickup_from
 * @property Carbon|null $pickup_to
 * @property Carbon|null $delivery_from
 * @property Carbon|null $delivery_to
 * @property string $weight_kg
 * @property string $volume_cbm
 * @property int $pallets
 * @property string|null $distance_km
 * @property string|null $goods_description
 * @property string|null $carrier_id
 * @property string $currency
 * @property string|null $expected_cost
 * @property string|null $actual_cost
 * @property string|null $trip_id
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class TmsTransportOrder extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['draft', 'planned', 'in_transit', 'delivered', 'cancelled', 'exception'];

    public const string Draft = 'draft';

    public const string Planned = 'planned';

    public const string InTransit = 'in_transit';

    public const string Delivered = 'delivered';

    public const string Cancelled = 'cancelled';

    public const string Exception = 'exception';

    protected $table = 'tms_transport_orders';

    protected function casts(): array
    {
        return [
            'pickup_from' => 'datetime',
            'pickup_to' => 'datetime',
            'delivery_from' => 'datetime',
            'delivery_to' => 'datetime',
            'weight_kg' => 'decimal:3',
            'volume_cbm' => 'decimal:3',
            'pallets' => 'integer',
            'distance_km' => 'decimal:2',
            'expected_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TmsCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TmsCarrier::class, 'carrier_id');
    }

    /**
     * @return BelongsTo<TmsTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(TmsTrip::class, 'trip_id');
    }

    /**
     * Whether the delivery met the end of the delivery window; null while undelivered or without a window.
     */
    public function isOnTime(): ?bool
    {
        if ($this->delivered_at === null || $this->delivery_to === null) {
            return null;
        }

        return $this->delivered_at->lessThanOrEqualTo($this->delivery_to);
    }
}
