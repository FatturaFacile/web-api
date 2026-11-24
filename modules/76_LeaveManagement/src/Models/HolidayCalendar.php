<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Country/canton/province/municipality holiday calendar for Mod 074.
 *
 * @property string $code
 * @property string $name
 * @property string $country
 * @property string|null $canton_or_province_code
 * @property string|null $municipality_name
 * @property string|null $municipality_patron_saint_date
 * @property string|null $municipality_patron_saint_name
 * @property int $year
 * @property string $sunday_holiday_rule
 * @property array<int, array<string, mixed>>|null $holidays
 * @property array<int, string>|null $working_days
 * @property bool $is_active
 */
final class HolidayCalendar extends BaseEnterpriseModel
{
    protected $table = 'lm_holiday_calendars';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'holidays' => 'array',
            'working_days' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
