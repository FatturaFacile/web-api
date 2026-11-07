<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

/**
 * Amounts as decimal strings, calculated with bcmath at two decimals.
 */
final class Money
{
    private const int Scale = 2;

    public static function normalize(string|int|float|null $amount): string
    {
        $value = is_float($amount) ? number_format($amount, 6, '.', '') : trim((string) ($amount ?? '0'));

        return bcadd(preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : '0', '0', self::Scale);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::Scale);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::Scale);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, self::Scale);
    }

    public static function isPositive(string $amount): bool
    {
        return self::compare($amount, '0') > 0;
    }

    /**
     * The percentage of an amount, rounded half up to the cent.
     */
    public static function percentage(string $amount, string $percentage): string
    {
        return self::round(bcdiv(bcmul($amount, $percentage, 6), '100', 6));
    }

    /**
     * The net of an amount that includes VAT at the given rate, rounded half up to the cent.
     */
    public static function netOfVat(string $gross, string $rate): string
    {
        return self::round(bcdiv(bcmul($gross, '100', 6), bcadd('100', $rate, 6), 6));
    }

    /**
     * Splits an amount in equal parts. The cents that do not divide exactly go to the last part, so the parts always
     * add up to the amount.
     *
     * @return list<string>
     */
    public static function split(string $amount, int $parts): array
    {
        $share = bcdiv($amount, (string) $parts, self::Scale);
        $result = array_fill(0, $parts, $share);
        $result[$parts - 1] = bcsub($amount, bcmul($share, (string) ($parts - 1), self::Scale), self::Scale);

        return $result;
    }

    private static function round(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', self::Scale);
    }
}
