<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — CareScope. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum CareScope: string
{
    use LabelledEnum;

    case Wheel = 'wheel';
    case Shelf = 'shelf';
    case Rack = 'rack';
    case Batch = 'batch';
}
