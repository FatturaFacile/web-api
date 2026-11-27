<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Exact arithmetic of the split of a foreign VAT amount into the part claimed back and the part that stays a cost.
 */
final class ForeignVatCalculator
{
    /**
     * @return array{recoverable: string, non_recoverable: string}
     */
    public function split(string $vatAmount, string $deductiblePercentage): array
    {
        $vat = Decimal::round2($vatAmount);
        $percentage = Decimal::compare($deductiblePercentage, '0') < 0 ? '0' : (Decimal::compare($deductiblePercentage, '100') > 0 ? '100' : $deductiblePercentage);
        $recoverable = Decimal::round2(Decimal::div(Decimal::mul($vat, $percentage), '100'));

        return ['recoverable' => $recoverable, 'non_recoverable' => Decimal::round2(Decimal::sub($vat, $recoverable))];
    }

    /**
     * The amount in the accounting currency of the company (rate = accounting units per foreign unit).
     */
    public function toBase(string $amount, string $exchangeRate): string
    {
        return Decimal::round2(Decimal::mul($amount, $exchangeRate));
    }

    /**
     * VAT of a net amount at a percentage rate.
     */
    public function vatOf(string $net, string $ratePercentage): string
    {
        return Decimal::round2(Decimal::div(Decimal::mul($net, $ratePercentage), '100'));
    }
}
