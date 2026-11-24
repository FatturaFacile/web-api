<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Value checks of the CU telematic supply: tax codes with their check character, dates in ddmmyyyy, amounts in euro cents
 * and the character set of the alphanumeric fields.
 */
final class CuFieldValidator
{
    /** Characters not allowed in the alphanumeric fields of the supply (after upper-casing and transliteration). */
    private const string FORBIDDEN = '/[^A-Z0-9 .,&@_+\/\-]/';

    /** A personal (16 characters) or company (11 digits) tax code with a correct check character. */
    public static function isValidTaxCode(string $taxCode): bool
    {
        $taxCode = strtoupper(trim($taxCode));

        return in_array(strlen($taxCode), [11, 16], true) && FiscalIdentifiers::isValidItalianFiscalCode($taxCode);
    }

    /** An 11 digit Partita IVA, with or without the IT prefix, with its check digit. */
    public static function isValidVatNumber(string $vat): bool
    {
        return FiscalIdentifiers::isValidItalianVat(preg_replace('/^IT/i', '', trim($vat)) ?? '');
    }

    /** A date in ddmmyyyy that exists on the calendar. */
    public static function isValidDate(string $ddmmyyyy): bool
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $ddmmyyyy, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }

    public static function formatDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : CarbonImmutable::instance($date)->format('dmY');
    }

    /** An amount in cents: digits only, at most $length of them. */
    public static function isValidCents(string $cents, int $length): bool
    {
        $significant = ltrim($cents, '0');

        return preg_match('/^\d+$/', $cents) === 1 && strlen($significant) <= $length;
    }

    /** Upper case ASCII text limited to the characters of the supply. */
    public static function sanitizeText(string $text): string
    {
        $clean = preg_replace(self::FORBIDDEN, ' ', strtoupper(Str::ascii($text))) ?? '';

        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    public static function isValidPostalCode(string $code): bool
    {
        return preg_match('/^\d{5}$/', $code) === 1;
    }

    public static function isValidProvince(string $province): bool
    {
        return preg_match('/^[A-Za-z]{2}$/', $province) === 1;
    }
}
