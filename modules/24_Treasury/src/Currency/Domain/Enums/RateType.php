<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

/**
 * What a rate is for. Mid is the daily reference rate used for documents and the ledger; Contract is a rate fixed for a
 * contract, an order or a document; VatEstvMonthly is the ESTV monthly average used for Swiss VAT (Info IVA 16); Closing is
 * the period-end rate of a revaluation.
 */
enum RateType: string
{
    case Mid = 'mid';
    case Buy = 'buy';
    case Sell = 'sell';
    case Contract = 'contract';
    case VatEstvMonthly = 'vat_estv_monthly';
    case Closing = 'closing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
