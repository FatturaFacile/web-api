<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum Treatment: string
{
    case CreditNote = 'credit_note';
    case PromoInvoice = 'promo_invoice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
