<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * How many people a department needs on a shift type, for weekdays, weekends or holidays.
 */
final class StaffingNeed extends BaseEnterpriseModel
{
    public const string Weekday = 'weekday';

    public const string Weekend = 'weekend';

    public const string Holiday = 'holiday';

    /** @var list<string> */
    public const array DayKinds = [self::Weekday, self::Weekend, self::Holiday];

    protected $table = 'hr_staffing_needs';

    protected function casts(): array
    {
        return ['required_count' => 'integer', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
