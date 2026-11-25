<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A day of driving read from a tachograph file (event_type activity_day) or an infringement found in it (event_type infringement).
 *
 * @property string $driver_employee_id
 * @property string|null $ddd_archive_id
 * @property string $event_type
 * @property Carbon $event_date
 * @property int $driving_minutes
 * @property int $work_minutes
 * @property int $longest_rest_minutes
 * @property string|null $infringement_code
 * @property int $infringement_minutes
 */
final class DriverTachographEvent extends BaseEnterpriseModel
{
    public const string ActivityDay = 'activity_day';

    public const string Infringement = 'infringement';

    protected $table = 'ptr_driver_tachograph_events';

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'driving_minutes' => 'integer',
            'work_minutes' => 'integer',
            'longest_rest_minutes' => 'integer',
            'infringement_minutes' => 'integer',
        ];
    }
}
