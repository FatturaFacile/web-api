<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — SettlementDocumentType. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum SettlementDocumentType: string
{
    use LabelledEnum;

    case SelfBilling = 'self_billing';
    case CreditNote = 'credit_note';
    case Invoice = 'invoice';
}
