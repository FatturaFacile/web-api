<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — WheelGrade. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum WheelGrade: string
{
    use LabelledEnum;

    case Ungraded = 'ungraded';
    case FirstCategory = 'first_category';
    case SecondCategory = 'second_category';
    case Discard = 'discard';
}
