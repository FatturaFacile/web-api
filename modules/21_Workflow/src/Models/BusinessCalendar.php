<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BusinessCalendar extends BaseEnterpriseModel
{
    protected $table = 'business_calendars';

    protected function casts(): array
    {
        return [
            'work_monday' => 'boolean',
            'work_tuesday' => 'boolean',
            'work_wednesday' => 'boolean',
            'work_thursday' => 'boolean',
            'work_friday' => 'boolean',
            'work_saturday' => 'boolean',
            'work_sunday' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(BusinessCalendarHoliday::class, 'business_calendar_id');
    }
}
