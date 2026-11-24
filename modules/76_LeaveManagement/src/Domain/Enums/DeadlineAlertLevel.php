<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

enum DeadlineAlertLevel: string
{
    case NONE = 'none';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
    case EXPIRED = 'expired';
}
