<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A journey with the own vehicle of the employee, reimbursed by kilometre. The rate in force is snapshotted when the
 * claim is saved.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $trip_id
 * @property Carbon $travel_date
 * @property string $from_location
 * @property string $to_location
 * @property string $vehicle_type
 * @property bool $round_trip
 * @property string $distance_km
 * @property string $rate_per_km
 * @property string $currency
 * @property string $amount
 * @property string|null $notes
 */
final class TravelMileageClaim extends BaseEnterpriseModel
{
    protected $table = 'travel_mileage_claims';

    protected function casts(): array
    {
        return [
            'travel_date' => 'date',
            'round_trip' => 'boolean',
            'distance_km' => 'decimal:2',
            'rate_per_km' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }
}
