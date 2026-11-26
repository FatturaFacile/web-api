<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Converts the lines of a document into the functional currency: each line to the cent (half up), the total of the document is
 * the sum of its lines, and the rounding residual (at most one cent per line) is absorbed by the line of the largest amount.
 * The accounting conversion stays at two decimals: the 0.05 CHF cash rounding is a rule of the issued document total only.
 */
final class FxAmountConverter
{
    /**
     * @param  list<string>  $lineAmounts  amounts in the document currency (credit-note lines negative)
     * @return array{lines: list<string>, total: string, residual: string}
     */
    public function convertLines(array $lineAmounts, ExchangeRateValue $rate): array
    {
        if ($lineAmounts === []) {
            return ['lines' => [], 'total' => '0.00', 'residual' => '0.00'];
        }

        $converted = [];
        $currencyTotal = '0';
        $largest = 0;

        foreach ($lineAmounts as $index => $amount) {
            $converted[$index] = Dec::money(Dec::mul($amount, $rate->value));
            $currencyTotal = Dec::add($currencyTotal, $amount);

            if (Dec::cmp(Dec::abs($amount), Dec::abs($lineAmounts[$largest])) === 1) {
                $largest = $index;
            }
        }

        $total = Dec::money(Dec::mul($currencyTotal, $rate->value));
        $sum = '0';

        foreach ($converted as $value) {
            $sum = Dec::add($sum, $value);
        }

        $residual = Dec::money(Dec::sub($total, $sum));

        if (Dec::cmp(Dec::abs($residual), Dec::mul('0.01', (string) count($converted))) === 1) {
            throw new CurrencyDomainException('The rounding residual of the conversion exceeds one cent per line.', 'fx_rounding_residual_too_large', 422);
        }

        if (! Dec::isZero($residual)) {
            $converted[$largest] = Dec::money(Dec::add($converted[$largest], $residual));
        }

        return ['lines' => array_values($converted), 'total' => $total, 'residual' => $residual];
    }

    /**
     * The total of a document issued in CHF, rounded to 0.05 (Swiss cash rounding); the difference is a rounding line of the
     * document, never part of the accounting counter-values.
     *
     * @return array{total: string, rounding: string}
     */
    public function swissDocumentTotal(string $total): array
    {
        $rounded = Dec::roundToFiveCents($total);

        return ['total' => $rounded, 'rounding' => Dec::money(Dec::sub($rounded, $total))];
    }
}
