<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — SettlementStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum SettlementStatus: string
{
    use LabelledEnum;

    case Draft = 'draft';
    case Approved = 'approved';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
}
