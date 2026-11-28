<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — InhibitorResult. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum InhibitorResult: string
{
    use LabelledEnum;

    case Negative = 'negative';
    case Positive = 'positive';
    case NotTested = 'not_tested';
}
