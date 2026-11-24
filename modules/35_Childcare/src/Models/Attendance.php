<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Carbon\CarbonImmutable;

/**
 * The attendance of a child on a day: check-in and check-out from the tablet, who picked the child up, temperature.
 *
 * @property string $id
 * @property string $child_id
 * @property CarbonImmutable $date
 * @property string $status
 * @property ?string $absence_reason
 * @property ?CarbonImmutable $check_in_at
 * @property ?CarbonImmutable $check_out_at
 * @property ?string $picked_up_by
 * @property ?string $temperature
 */
final class Attendance extends ChildcareRecord
{
    /** @var list<string> fields an authorised correction may change */
    public const array CorrectableFields = ['status', 'absence_reason', 'check_in_at', 'check_out_at', 'temperature'];

    protected $table = 'cc_attendances';

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'check_in_at' => 'immutable_datetime', 'check_out_at' => 'immutable_datetime', 'temperature' => 'encrypted'];
    }
}
