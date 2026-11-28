<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — TankStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum TankStatus: string
{
    use LabelledEnum;

    case Active = 'active';
    case Cleaning = 'cleaning';
    case OutOfService = 'out_of_service';
}
