<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — WheelStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum WheelStatus: string
{
    use LabelledEnum;

    case Brine = 'brine';
    case Aging = 'aging';
    case Sold = 'sold';
    case Recalled = 'recalled';
}
