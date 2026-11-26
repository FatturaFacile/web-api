<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon $holiday_date
 * @property string $name
 * @property ?string $holiday_type
 * @property ?string $region
 */
final class BusinessCalendarHoliday extends BaseEnterpriseModel
{
    protected $table = 'business_calendar_holidays';

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }
}
