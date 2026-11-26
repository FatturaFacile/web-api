<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * E.164 phone numbers: a plus sign, a country code that does not start with 0, and at most 15 digits in all.
 */
final class PhoneNumber
{
    public const PATTERN = '/^\+[1-9]\d{7,14}$/';

    /**
     * Strips the spaces, dots, dashes and brackets people type, keeping the plus sign.
     */
    public static function normalize(string $number): string
    {
        return (string) preg_replace('/[\s().-]+/', '', trim($number));
    }

    public static function isValid(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1;
    }

    /**
     * Shows the start and the last two digits only (+41*******67).
     */
    public static function mask(string $number): string
    {
        return strlen($number) > 6 ? substr($number, 0, 3).str_repeat('*', strlen($number) - 5).substr($number, -2) : '***';
    }
}
