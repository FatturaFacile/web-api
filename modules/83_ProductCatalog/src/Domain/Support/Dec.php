<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on strings (bcmath): floats never enter a price, cost or conversion. Intermediate results keep twelve
 * decimals, only the stored figure is rounded.
 */
final class Dec
{
    public const int SCALE = 12;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', self::SCALE);
        }

        $text = is_float($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("Not a decimal number: [{$text}].");
        }

        return bcadd($text, '0', self::SCALE);
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
        if (self::isZero($b)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return self::cmp($value, 0) === 0;
    }

    /**
     * Rounds to the given decimals: half away from zero (`half_up`), always away from zero (`up`) or towards zero (`down`).
     */
    public static function round(string|int|float|null $value, int $decimals, string $mode = 'half_up'): string
    {
        $number = self::of($value);
        $negative = str_starts_with($number, '-');
        $abs = ltrim($number, '-');
        $factor = bcpow('10', (string) $decimals, 0);
        $scaled = bcmul($abs, $factor, self::SCALE);
        $whole = bcadd($scaled, '0', 0);
        $fraction = bcsub($scaled, $whole, self::SCALE);
        $hasFraction = bccomp($fraction, '0', self::SCALE) > 0;

        $whole = match ($mode) {
            'up' => $hasFraction ? bcadd($whole, '1', 0) : $whole,
            'down' => $whole,
            default => bccomp($fraction, '0.5', self::SCALE) >= 0 ? bcadd($whole, '1', 0) : $whole,
        };

        $result = bcdiv($whole, $factor, $decimals);

        return $negative && bccomp($result, '0', $decimals) !== 0 ? '-'.$result : $result;
    }

    /**
     * Whether the value has no digits beyond the given decimals.
     */
    public static function fits(string|int|float|null $value, int $decimals): bool
    {
        return self::cmp($value, self::round($value, $decimals, 'down')) === 0;
    }

    /**
     * The value with trailing zeros removed (`12.500000` becomes `12.5`).
     */
    public static function trim(string|int|float|null $value): string
    {
        $text = self::of($value);

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
