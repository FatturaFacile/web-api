<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — CareType. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum CareType: string
{
    use LabelledEnum;

    case Brining = 'brining';
    case Turning = 'turning';
    case Brushing = 'brushing';
    case RindWashing = 'rind_washing';
    case Piercing = 'piercing';
    case ExpertGrading = 'expert_grading';
}
