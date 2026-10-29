<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class BusinessHoursCalendar extends BaseEnterpriseModel
{
    protected $table = 'esm_business_hours_calendars';

    protected function casts(): array
    {
        return [
            'weekly_schedule' => 'array',
            'holidays' => 'array',
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }
}
