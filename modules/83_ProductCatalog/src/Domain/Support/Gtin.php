<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Support;

/**
 * GS1 check digit arithmetic for GTIN-8, GTIN-12, EAN-13 and GTIN-14, and deterministic generation of in-house EAN-13 codes
 * (restricted-circulation prefix 2, so a generated code never collides with a GS1 company prefix).
 */
final class Gtin
{
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValid(?string $code): bool
    {
        if ($code === null || ! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return false;
        }

        return self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /**
     * An in-house EAN-13 built from a numeric sequence: `2` + the sequence padded to eleven digits + the check digit.
     */
    public static function inHouseEan13(int $sequence): string
    {
        $body = '2'.str_pad((string) $sequence, 11, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /**
     * The GTIN-14 of a logistic unit: indicator digit + the first twelve digits of the EAN-13 + the new check digit.
     */
    public static function toGtin14(string $ean13, int $indicator): string
    {
        $body = (string) $indicator.substr($ean13, 0, 12);

        return $body.self::checkDigit($body);
    }
}
