<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

/**
 * Policy applied when a statutory holiday falls on a Sunday (festività cadenti di domenica).
 */
enum SundayHolidayRule: string
{
    case COMPENSATORY_PAY_OR_DAY = 'compensatory_pay_or_day';
    case COMPENSATORY_PAY = 'compensatory_pay';
    case NONE = 'none';
}
