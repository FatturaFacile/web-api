<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class HolidayCalendar extends BaseEnterpriseModel
{
    protected $table = 'hr_holiday_calendars';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'daily_standard_hours' => 'decimal:2',
            'working_days' => 'array',
            'holidays' => 'array',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }
}
