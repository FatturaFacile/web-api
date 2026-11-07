<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Casts\UtcInstant;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

/**
 * A meal booked at the clock. It is not a time event: it never opens or closes a working period.
 *
 * @property CarbonImmutable $occurred_at
 */
final class TimeMealBooking extends BaseEnterpriseModel
{
    protected $table = 'time_meal_bookings';

    protected function casts(): array
    {
        return [
            'meal_date' => 'date:Y-m-d',
            'occurred_at' => UtcInstant::class,
            'source' => TimeRecordSource::class,
        ];
    }
}
