<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum ScheduleOccurrence: string
{
    case Daily = 'daily';
    case Weekday = 'weekday';
    case Date = 'date';
}
