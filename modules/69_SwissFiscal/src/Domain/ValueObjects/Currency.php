<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

/**
 * Currencies of the omnichannel price lists. Every amount is stored in integer minor units; the cash increment is the
 * smallest coin that can physically settle a cash payment (0.05 CHF in Switzerland).
 */
enum Currency: string
{
    case CHF = 'CHF';
    case EUR = 'EUR';
    case USD = 'USD';

    /** Number of decimals of the minor unit (all supported currencies use 2). */
    public function minorUnits(): int
    {
        return 2;
    }

    /** Smallest cash-payable step, expressed in minor units (CHF: 5 rappen). */
    public function cashIncrementMinor(): int
    {
        return match ($this) {
            self::CHF => 5,
            self::EUR, self::USD => 1,
        };
    }

    /** Whether the Swiss QR-bill standard accepts the currency. */
    public function isQrBillCurrency(): bool
    {
        return $this === self::CHF || $this === self::EUR;
    }
}
