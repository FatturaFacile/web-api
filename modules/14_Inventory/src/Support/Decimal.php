<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use InvalidArgumentException;

/**
 * Arbitrary precision decimal helpers (bcmath) for inventory valuation.
 *
 * All values are numeric strings. Rounding is half-up (away from zero), as required
 * for unit costs (4 decimals) and monetary totals (2 decimals).
 */
final class Decimal
{
    public const int SCALE = 10;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::normalise(number_format($value, self::SCALE, '.', ''));
        }

        $value = trim($value);
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Value [{$value}] is not numeric.");
        }

        if (stripos($value, 'e') !== false) {
            return self::normalise(number_format((float) $value, self::SCALE, '.', ''));
        }

        return self::normalise($value);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string|int|float|null $a, string|int|float|null $b): string
    {
        $divisor = self::of($b);
        if (self::isZero($divisor)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::of($a), $divisor, self::SCALE);
    }

    public static function comp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === 0;
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === 1;
    }

    public static function isNegative(string|int|float|null $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === -1;
    }

    public static function min(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::comp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::comp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function neg(string|int|float|null $value): string
    {
        return bcmul(self::of($value), '-1', self::SCALE);
    }

    public static function abs(string|int|float|null $value): string
    {
        $v = self::of($value);

        return self::isNegative($v) ? self::neg($v) : $v;
    }

    /**
     * Round half-up (away from zero) and return a fixed-scale string, e.g. round('2.345', 2) === '2.35'.
     */
    public static function round(string|int|float|null $value, int $scale): string
    {
        $v = self::of($value);
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = self::isNegative($v)
            ? bcsub($v, $half, $scale + 1)
            : bcadd($v, $half, $scale + 1);

        // bcadd with a lower scale truncates toward zero.
        $rounded = bcadd($adjusted, '0', $scale);

        // Avoid "-0.00".
        if (bccomp($rounded, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }

        return $rounded;
    }

    public static function money(string|int|float|null $value): string
    {
        return self::round($value, 2);
    }

    public static function unitCost(string|int|float|null $value): string
    {
        return self::round($value, 4);
    }

    public static function quantity(string|int|float|null $value): string
    {
        return self::round($value, 4);
    }

    /**
     * Largest-remainder (Hamilton) allocation: splits $total (2 decimals) proportionally to the
     * given non-negative weights so that the parts sum exactly to $total.
     *
     * @param  array<array-key, string|int|float>  $weights
     * @return array<array-key, string> parts with 2 decimals, same keys as $weights
     */
    public static function allocate(string|int|float $total, array $weights): array
    {
        $totalCents = self::round(self::mul(self::money($total), '100'), 0);
        $sumWeights = '0';
        foreach ($weights as $weight) {
            if (self::isNegative($weight)) {
                throw new InvalidArgumentException('Allocation weights must be non-negative.');
            }
            $sumWeights = self::add($sumWeights, $weight);
        }

        if (! self::isPositive($sumWeights)) {
            throw new InvalidArgumentException('Allocation requires at least one positive weight.');
        }

        $sign = self::isNegative($totalCents) ? '-1' : '1';
        $absCents = self::abs($totalCents);

        $floors = [];
        $remainders = [];
        $assigned = '0';
        $order = 0;
        foreach ($weights as $key => $weight) {
            $raw = bcdiv(bcmul($absCents, self::of($weight), self::SCALE), $sumWeights, self::SCALE);
            $floor = bcadd($raw, '0', 0); // truncation == floor for non-negative values
            $floors[$key] = $floor;
            $remainders[] = ['key' => $key, 'remainder' => bcsub($raw, $floor, self::SCALE), 'weight' => self::of($weight), 'order' => $order++];
            $assigned = bcadd($assigned, $floor, 0);
        }

        $leftover = (int) bcsub($absCents, $assigned, 0);

        usort($remainders, static function (array $a, array $b): int {
            $byRemainder = bccomp($b['remainder'], $a['remainder'], self::SCALE);
            if ($byRemainder !== 0) {
                return $byRemainder;
            }

            $byWeight = bccomp($b['weight'], $a['weight'], self::SCALE);

            return $byWeight !== 0 ? $byWeight : $a['order'] <=> $b['order'];
        });

        for ($i = 0; $i < $leftover; $i++) {
            $key = $remainders[$i % count($remainders)]['key'];
            $floors[$key] = bcadd($floors[$key], '1', 0);
        }

        $parts = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = bcdiv(bcmul($floors[$key], $sign, 0), '100', 2);
        }

        return $parts;
    }

    private static function normalise(string $value): string
    {
        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }
        if (str_starts_with($value, '.')) {
            $value = '0'.$value;
        }
        if (str_starts_with($value, '-.')) {
            $value = '-0'.substr($value, 1);
        }

        return $value;
    }
}
