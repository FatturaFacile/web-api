<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — LabFormat. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum LabFormat: string
{
    use LabelledEnum;

    case Suisselab = 'suisselab';
    case Tsm = 'tsm';
    case Aia = 'aia';
    case Aral = 'aral';
    case GenericCsv = 'generic_csv';
}
