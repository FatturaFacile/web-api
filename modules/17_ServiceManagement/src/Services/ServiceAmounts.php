<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Exact money and quantity arithmetic of the service module. Callers of the public contracts may still hand in a
 * number (PHP int or float) read from JSON; it is turned into a decimal string once, at the border, and every product
 * and sum after that is done with bcmath, so a work order total never drifts by a cent.
 */
final class ServiceAmounts
{
    /**
     * A decimal string from a request or DTO value.
     */
    public static function text(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
        }

        return Decimal::normalize((string) $value);
    }

    /**
     * An amount rounded to cents, as the database stores it.
     */
    public static function money(string|int|float|null $value): string
    {
        return Decimal::round2(self::text($value));
    }

    /**
     * Quantity times unit amount, rounded to cents.
     */
    public static function lineTotal(string|int|float|null $quantity, string|int|float|null $unitAmount): string
    {
        return Decimal::round2(Decimal::mul(self::text($quantity), self::text($unitAmount)));
    }

    /**
     * Sum of two amounts, rounded to cents.
     */
    public static function add(string|int|float|null $left, string|int|float|null $right): string
    {
        return Decimal::round2(Decimal::add(self::text($left), self::text($right)));
    }
}
