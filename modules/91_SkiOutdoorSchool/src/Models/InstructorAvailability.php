<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\AvailabilityKind;

/**
 * An explicit availability window or a blackout of an instructor on one day.
 *
 * @property string $instructor_id
 * @property Carbon $slot_date
 * @property string $start_time
 * @property string $end_time
 * @property AvailabilityKind $kind
 * @property string|null $note
 */
final class InstructorAvailability extends BaseEnterpriseModel
{
    protected $table = 'ski_instructor_availabilities';

    protected function casts(): array
    {
        return [
            'slot_date' => 'date:Y-m-d',
            'kind' => AvailabilityKind::class,
        ];
    }
}
