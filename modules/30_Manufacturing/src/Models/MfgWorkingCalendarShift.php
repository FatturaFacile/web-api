<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A shift of one ISO weekday (1 Monday .. 7 Sunday). A shift crossing midnight belongs to the day it starts on.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $working_calendar_id
 * @property int $day_of_week
 * @property string $shift_code
 * @property string $start_time
 * @property string $end_time
 * @property bool $crosses_midnight
 * @property int $break_minutes
 * @property int $net_available_minutes
 * @property string $labor_multiplier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgWorkingCalendarShift extends BaseEnterpriseModel
{
    protected $table = 'mfg_working_calendar_shifts';

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer',
            'net_available_minutes' => 'integer',
            'labor_multiplier' => 'decimal:4',
        ];
    }

    /**
     * Net available minutes of a shift: its clock span (across midnight when the end is not after the start) minus
     * the breaks, never negative.
     */
    public static function netMinutes(string $start, string $end, int $breakMinutes): int
    {
        $from = self::minuteOfDay($start);
        $to = self::minuteOfDay($end);
        $span = $to > $from ? $to - $from : $to + 1440 - $from;

        return max(0, $span - $breakMinutes);
    }

    /**
     * Minute of the day (0..1439) of an HH:mm time.
     */
    public static function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
