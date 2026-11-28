<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — VatBatchStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum VatBatchStatus: string
{
    use LabelledEnum;

    case Open = 'open';
    case InProduction = 'in_production';
    case Completed = 'completed';
    case Aging = 'aging';
    case Closed = 'closed';
}
