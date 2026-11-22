<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * What a calendar day is for planning.
 */
enum CalendarDayType: string
{
    case WorkingShift = 'working_shift';
    case Holiday = 'holiday';
    case NonWorkingWeekend = 'non_working_weekend';
    case PlannedShutdown = 'planned_shutdown';
}
