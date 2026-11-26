<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

/**
 * Which rate converts the VAT of a foreign-currency document: the ESTV monthly average (Switzerland), the daily rate of the
 * document date, or the ECB reference rate (Italy, Art. 13 c. 4 DPR 633/72).
 */
enum VatRateMethod: string
{
    case EstvMonthly = 'estv_monthly';
    case Daily = 'daily';
    case Ecb = 'ecb';
}
