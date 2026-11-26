<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Cross rates through a pivot currency (the ECB publishes everything against EUR): 1 A = (units of pivot per A) / (units
 * of pivot per B) B. The legs are used at full precision and only the result is rounded to six decimals.
 */
final class CrossRateCalculator
{
    /**
     * @param  string  $aToPivot  pivot units for one unit of A (rate of the pair A/pivot)
     * @param  string  $bToPivot  pivot units for one unit of B (rate of the pair B/pivot)
     */
    public function cross(string $aToPivot, string $bToPivot): ExchangeRateValue
    {
        if (! Dec::isPositive($aToPivot) || ! Dec::isPositive($bToPivot)) {
            throw new InvalidArgumentException('The legs of a cross rate must be greater than zero.');
        }

        return ExchangeRateValue::of(Dec::div($aToPivot, $bToPivot));
    }

    /**
     * Every ordered pair among some currencies from their rates against the pivot.
     *
     * @param  array<string, string>  $perPivotUnit  currency => units of the currency for one unit of the pivot (the pivot itself is implied with 1)
     * @param  list<string>  $currencies  currencies to combine (the pivot may be among them)
     * @return list<array{pair: CurrencyPair, rate: ExchangeRateValue}>
     */
    public function derivePairs(string $pivot, array $perPivotUnit, array $currencies): array
    {
        $pivot = strtoupper($pivot);
        $units = [$pivot => '1'];

        foreach ($perPivotUnit as $currency => $value) {
            $units[strtoupper($currency)] = $value;
        }

        $selected = array_values(array_unique(array_filter(array_map('strtoupper', $currencies), static fn (string $currency): bool => isset($units[$currency]))));
        $pairs = [];

        foreach ($selected as $a) {
            foreach ($selected as $b) {
                if ($a === $b) {
                    continue;
                }

                // 1 A = (1 / units[A]) pivot = (units[B] / units[A]) B
                $pairs[] = ['pair' => CurrencyPair::of($a, $b), 'rate' => ExchangeRateValue::of(Dec::div($units[$b], $units[$a]))];
            }
        }

        return $pairs;
    }
}
