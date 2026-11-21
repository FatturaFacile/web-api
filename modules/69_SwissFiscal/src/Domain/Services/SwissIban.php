<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Services;

/** IBAN validation for Swiss and Liechtenstein accounts, including QR-IBAN detection. */
final class SwissIban
{
    public static function normalize(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }

    /** A CH/LI IBAN: 21 characters with a valid ISO 7064 modulo-97 check. */
    public static function isValid(string $iban): bool
    {
        $compact = self::normalize($iban);

        return preg_match('/^(CH|LI)\d{2}[0-9A-Z]{17}$/', $compact) === 1
            && PaymentReference::mod97(substr($compact, 4).substr($compact, 0, 4)) === 1;
    }

    /** A QR-IBAN carries an institution identifier (characters 5 to 9) between 30000 and 31999. */
    public static function isQrIban(string $iban): bool
    {
        $compact = self::normalize($iban);

        if (preg_match('/^(CH|LI)\d{2}\d{5}/', $compact) !== 1) {
            return false;
        }

        $institution = (int) substr($compact, 4, 5);

        return $institution >= 30000 && $institution <= 31999;
    }

    /** Groups the IBAN in blocks of four for printing. */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }
}
