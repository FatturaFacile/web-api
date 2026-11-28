<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — MilkSession. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum MilkSession: string
{
    use LabelledEnum;

    case Morning = 'morning';
    case Evening = 'evening';
}
