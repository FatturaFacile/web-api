<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comparison of the active carriers for a shipment: the offers ranked cheapest first, and the carrier it was awarded to.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $reference
 * @property string|null $transport_order_id
 * @property Carbon $ship_date
 * @property string $weight_kg
 * @property string|null $distance_km
 * @property string|null $origin_zone
 * @property string|null $destination_zone
 * @property string $currency
 * @property string $status
 * @property array<mixed>|null $offers
 * @property string|null $awarded_carrier_id
 * @property string|null $awarded_amount
 * @property Carbon|null $awarded_at
 * @property string|null $notes
 */
final class TmsQuote extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['open', 'awarded', 'cancelled'];

    public const string Open = 'open';

    public const string Awarded = 'awarded';

    public const string Cancelled = 'cancelled';

    protected $table = 'tms_quotes';

    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'weight_kg' => 'decimal:3',
            'distance_km' => 'decimal:2',
            'offers' => 'array',
            'awarded_amount' => 'decimal:2',
            'awarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TmsCarrier, $this>
     */
    public function awardedCarrier(): BelongsTo
    {
        return $this->belongsTo(TmsCarrier::class, 'awarded_carrier_id');
    }
}
