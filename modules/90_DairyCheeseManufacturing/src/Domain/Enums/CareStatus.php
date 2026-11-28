<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — CareStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum CareStatus: string
{
    use LabelledEnum;

    case Scheduled = 'scheduled';
    case Done = 'done';
    case Skipped = 'skipped';
}
