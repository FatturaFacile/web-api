<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

enum LeaveUnitMode: string
{
    case DAYS = 'days';
    case HOURS = 'hours';
}
