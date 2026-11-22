<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum EntryType: string
{
    case GL_ALLOCATION = 'GL_ALLOCATION';
    case SALES_INVOICE = 'SALES_INVOICE';
    case PURCHASE_INVOICE = 'PURCHASE_INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case STEP_DOWN_APPORTIONMENT = 'STEP_DOWN_APPORTIONMENT';
    case MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';
    case REVERSAL = 'REVERSAL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
