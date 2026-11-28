<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — Authority. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum Authority: string
{
    use LabelledEnum;

    case Dbmilch = 'dbmilch';
    case Sian = 'sian';
}
