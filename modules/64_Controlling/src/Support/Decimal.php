<?php

declare(strict_types=1);

namespace Modules\Controlling\Support;

/**
 * Exact decimal arithmetic (bcmath) at the 4 decimals of the controlling tables, with the deterministic penny-residual
 * spread used by every allocation, so that the parts always add up to the total.
 */
final class Decimal
{
    public const int Scale = 4;

    public static function norm(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd(is_float($value) ? number_format($value, 8, '.', '') : (string) $value, '0', self::Scale);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::norm($a), self::norm($b), self::Scale);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::norm($a), self::norm($b), self::Scale);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::round(bcmul(self::norm($a), self::norm($b), 12));
    }

    /**
     * Quotient, or zero when the divisor is zero.
     */
    public static function div(string|int|float|null $a, string|int|float|null $b, int $scale = self::Scale): string
    {
        $divisor = self::norm($b);

        return bccomp($divisor, '0', self::Scale) === 0 ? bcadd('0', '0', $scale) : bcdiv(self::norm($a), $divisor, $scale + 6);
    }

    public static function round(string $value, int $scale = self::Scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', 12) < 0 ? bcsub($value, $half, $scale + 1) : bcadd($value, $half, $scale + 1);

        return bcadd(bcadd($adjusted, '0', $scale), '0', $scale);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::norm($a), self::norm($b), self::Scale);
    }

    public static function isZero(string|int|float|null $a): bool
    {
        return self::cmp($a, 0) === 0;
    }

    public static function neg(string|int|float|null $a): string
    {
        return bcmul(self::norm($a), '-1', self::Scale);
    }

    public static function abs(string|int|float|null $a): string
    {
        return ltrim(self::norm($a), '-') ?: '0.0000';
    }

    /**
     * Percentage of the part over the base, 0 on an empty base.
     */
    public static function percent(string|int|float|null $part, string|int|float|null $base, int $scale = 2): string
    {
        if (self::isZero($base)) {
            return bcadd('0', '0', $scale);
        }

        return self::round(bcmul(bcdiv(self::norm($part), self::norm($base), 10), '100', 10), $scale);
    }

    /**
     * Split the total in proportion to the weights: every share is rounded to 4 decimals and the residual goes to the
     * line with the largest weight (the first one on a tie), so that the shares add up to the total exactly.
     *
     * @param  array<string|int, string|int|float>  $weights  non-negative weights by key
     * @return array<string|int, string> shares by key (all zero when every weight is zero)
     */
    public static function spread(string|int|float $total, array $weights): array
    {
        $total = self::norm($total);
        $weightSum = '0';
        foreach ($weights as $weight) {
            $weightSum = bcadd($weightSum, self::norm($weight), self::Scale);
        }
        $shares = [];
        if (bccomp($weightSum, '0', self::Scale) <= 0) {
            foreach ($weights as $key => $weight) {
                $shares[$key] = self::norm(0);
            }

            return $shares;
        }
        $largest = null;
        $assigned = '0.0000';
        foreach ($weights as $key => $weight) {
            $shares[$key] = self::round(bcmul($total, bcdiv(self::norm($weight), $weightSum, 12), 12));
            $assigned = bcadd($assigned, $shares[$key], self::Scale);
            if ($largest === null || bccomp(self::norm($weight), self::norm($weights[$largest]), self::Scale) > 0) {
                $largest = $key;
            }
        }
        $shares[$largest] = bcadd($shares[$largest], bcsub($total, $assigned, self::Scale), self::Scale);

        return $shares;
    }
}
