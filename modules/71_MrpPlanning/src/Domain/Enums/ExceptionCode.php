<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Exception messages the engine attaches to a proposal. */
enum ExceptionCode: string
{
    case Expedite = 'Expedite';
    case Defer = 'Defer';
    case PastDueRelease = 'PastDueRelease';
    case BelowSafetyStock = 'BelowSafetyStock';
    case CapacityOverload = 'CapacityOverload';
    case HolidayShiftApplied = 'HolidayShiftApplied';
    case CustomsLeadTimeApplied = 'CustomsLeadTimeApplied';
    case LateDelivery = 'LateDelivery';
    case ExcessSupply = 'ExcessSupply';
}
