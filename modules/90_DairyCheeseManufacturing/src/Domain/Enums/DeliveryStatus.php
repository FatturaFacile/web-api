<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — DeliveryStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum DeliveryStatus: string
{
    use LabelledEnum;

    case Registered = 'registered';
    case Quarantined = 'quarantined';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
