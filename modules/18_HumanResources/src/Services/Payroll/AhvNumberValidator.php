<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

/**
 * Validates and normalizes Swiss social security numbers (AHV/AVS number, format 756.XXXX.XXXX.XC).
 * The last digit is an EAN-13 check digit.
 */
final class AhvNumberValidator
{
    private const COUNTRY_PREFIX = '756';

    public static function isValid(string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * Returns the number in the canonical dotted format, or null when the input is not a valid AHV number.
     */
    public static function normalize(string $value): ?string
    {
        $digits = preg_replace('/[\s.]/', '', $value) ?? '';

        if (preg_match('/^\d{13}$/', $digits) !== 1 || ! str_starts_with($digits, self::COUNTRY_PREFIX)) {
            return null;
        }

        if (self::checkDigit(substr($digits, 0, 12)) !== (int) $digits[12]) {
            return null;
        }

        return sprintf('%s.%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 4), substr($digits, 7, 4), substr($digits, 11, 2));
    }

    /**
     * EAN-13 check digit: weights 1 and 3 alternate from the left over the first 12 digits.
     */
    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;

        for ($position = 0; $position < 12; $position++) {
            $sum += (int) $twelveDigits[$position] * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
