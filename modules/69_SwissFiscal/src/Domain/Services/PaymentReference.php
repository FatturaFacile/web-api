<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Services;

use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;

/**
 * Swiss payment references: the 27-digit QR reference (modulo-10 recursive checksum) used with a QR-IBAN, and the
 * ISO 11649 Creditor Reference ("SCOR", RF + two check digits, modulo 97) used with a regular IBAN.
 */
final class PaymentReference
{
    /** Carry table of the modulo-10 recursive algorithm. */
    private const array Mod10Table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    /** Builds a QR reference from up to 26 digits (left padded with zeros) plus the check digit. */
    public static function qrReference(string $digits): string
    {
        if (preg_match('/^\d{1,26}$/', $digits) !== 1) {
            throw SwissFiscalException::invalidQrBill('the QR reference base must contain 1 to 26 digits');
        }

        $base = str_pad($digits, 26, '0', STR_PAD_LEFT);

        return $base.self::mod10CheckDigit($base);
    }

    public static function isValidQrReference(string $reference): bool
    {
        $compact = str_replace(' ', '', $reference);

        return preg_match('/^\d{27}$/', $compact) === 1
            && self::mod10CheckDigit(substr($compact, 0, 26)) === (int) $compact[26];
    }

    /** Groups a QR reference as printed on the payment part: 2 + 5 x 5 digits. */
    public static function formatQrReference(string $reference): string
    {
        $compact = str_replace(' ', '', $reference);

        return trim(substr($compact, 0, 2).' '.implode(' ', str_split(substr($compact, 2), 5)));
    }

    /** Builds an ISO 11649 creditor reference from up to 21 alphanumeric characters. */
    public static function creditorReference(string $base): string
    {
        $normalized = strtoupper(str_replace(' ', '', $base));
        if (preg_match('/^[0-9A-Z]{1,21}$/', $normalized) !== 1) {
            throw SwissFiscalException::invalidQrBill('the creditor reference base must be 1 to 21 alphanumeric characters');
        }

        $check = 98 - self::mod97($normalized.'RF00');

        return 'RF'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$normalized;
    }

    public static function isValidCreditorReference(string $reference): bool
    {
        $compact = strtoupper(str_replace(' ', '', $reference));

        return preg_match('/^RF\d{2}[0-9A-Z]{1,21}$/', $compact) === 1
            && self::mod97(substr($compact, 4).substr($compact, 0, 4)) === 1;
    }

    public static function mod10CheckDigit(string $digits): int
    {
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = self::Mod10Table[($carry + (int) $digit) % 10];
        }

        return (10 - $carry) % 10;
    }

    /** ISO 7064 modulo 97 over an alphanumeric string (A=10 ... Z=35), computed in chunks to avoid big integers. */
    public static function mod97(string $alphanumeric): int
    {
        $numeric = '';
        foreach (str_split(strtoupper($alphanumeric)) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
