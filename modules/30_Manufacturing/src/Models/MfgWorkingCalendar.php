<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop-floor calendar: weekly shifts, planned shutdowns, and the country and region (Swiss canton, Italian province)
 * whose public holidays close the plant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $timezone
 * @property string $country_code
 * @property string|null $region_code
 * @property string|null $inherit_holiday_calendar_id
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgWorkingCalendarShift> $shifts
 * @property-read Collection<int, MfgWorkingCalendarClosure> $closures
 */
final class MfgWorkingCalendar extends BaseEnterpriseModel
{
    protected $table = 'mfg_working_calendars';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MfgWorkingCalendarShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(MfgWorkingCalendarShift::class, 'working_calendar_id')->orderBy('day_of_week')->orderBy('start_time');
    }

    /**
     * @return HasMany<MfgWorkingCalendarClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(MfgWorkingCalendarClosure::class, 'working_calendar_id')->orderBy('starts_on');
    }
}
