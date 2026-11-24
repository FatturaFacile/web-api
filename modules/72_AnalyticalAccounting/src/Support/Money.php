<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic on minor units (cents, integers) with decimal strings at the edges, and the Largest Remainder
 * (Hamilton) apportionment used by every split of the module so the parts always add up to the total with zero drift.
 */
final class Money
{
    /**
     * Decimal string (any scale) to cents, rounded half away from zero.
     */
    public static function toCents(string|int|float|null $amount): int
    {
        $value = $amount === null || $amount === '' ? '0' : (is_float($amount) ? number_format($amount, 8, '.', '') : (string) $amount);
        $scaled = bcmul($value, '100', 6);
        $half = bccomp($scaled, '0', 6) < 0 ? '-0.5' : '0.5';

        return (int) bcadd($scaled, $half, 0);
    }

    /**
     * Cents to a decimal string with 4 decimals, the scale of the stored columns.
     */
    public static function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 4);
    }

    /**
     * Largest Remainder (Hamilton) split of an amount of cents over non-negative decimal-string weights.
     * Ties on the fractional remainder go to the earliest key. An all-zero weight vector yields all zeros.
     *
     * @param  array<string|int, string>  $weights
     * @return array<string|int, int>
     */
    public static function allocate(int $totalCents, array $weights): array
    {
        $shares = [];
        $sum = '0';
        foreach ($weights as $key => $weight) {
            if (bccomp($weight, '0', 8) < 0) {
                throw new InvalidArgumentException('Allocation weights cannot be negative.');
            }
            $shares[$key] = 0;
            $sum = bcadd($sum, $weight, 8);
        }
        if (bccomp($sum, '0', 8) === 0) {
            return $shares;
        }

        $negative = $totalCents < 0;
        $magnitude = (string) abs($totalCents);
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $key => $weight) {
            $numerator = bcmul($magnitude, $weight, 8);
            $quotient = bcdiv($numerator, $sum, 12);
            $floor = (int) bcadd($quotient, '0', 0);
            $shares[$key] = $floor;
            $assigned += $floor;
            $remainders[$key] = bcsub($quotient, (string) $floor, 12);
        }

        $leftover = abs($totalCents) - $assigned;
        $order = array_keys($remainders);
        $position = array_flip($order);
        usort($order, static function (string|int $a, string|int $b) use ($remainders, $position): int {
            $cmp = bccomp($remainders[$b], $remainders[$a], 12);

            return $cmp !== 0 ? $cmp : $position[$a] <=> $position[$b];
        });
        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i % count($order)]]++;
        }

        if ($negative) {
            foreach ($shares as $key => $cents) {
                $shares[$key] = -$cents;
            }
        }

        return $shares;
    }

    /**
     * Percentage with two decimals of part over base, "0.00" on an empty base.
     */
    public static function percent(string $part, string $base): string
    {
        if (bccomp($base, '0', 8) === 0) {
            return '0.00';
        }
        $ratio = bcmul(bcdiv($part, $base, 10), '100', 10);
        $half = bccomp($ratio, '0', 10) < 0 ? '-0.005' : '0.005';

        return bcadd($ratio, $half, 2);
    }
}
