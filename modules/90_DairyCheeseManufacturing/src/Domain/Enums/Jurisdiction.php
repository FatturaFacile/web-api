<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — Jurisdiction. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum Jurisdiction: string
{
    use LabelledEnum;

    case Ch = 'CH';
    case It = 'IT';
}
