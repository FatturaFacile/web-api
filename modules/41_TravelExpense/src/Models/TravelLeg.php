<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One leg of the itinerary of a trip: a flight, a train, a car transfer or a hotel stay (departure and arrival are
 * the check-in and check-out) with the cost booked for it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $trip_id
 * @property int $sequence
 * @property string $leg_type
 * @property string|null $from_location
 * @property string $to_location
 * @property Carbon|null $departs_at
 * @property Carbon|null $arrives_at
 * @property string|null $provider
 * @property string|null $booking_reference
 * @property string $booking_status
 * @property string $booked_cost
 * @property string $currency
 * @property string|null $notes
 */
final class TravelLeg extends BaseEnterpriseModel
{
    public const array Types = ['flight', 'train', 'car', 'hotel'];

    public const array BookingStatuses = ['planned', 'booked', 'cancelled'];

    protected $table = 'travel_legs';

    protected function casts(): array
    {
        return [
            'departs_at' => 'datetime',
            'arrives_at' => 'datetime',
            'sequence' => 'integer',
            'booked_cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TravelTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(TravelTrip::class, 'trip_id');
    }
}
