<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Support;

/**
 * Format rules of the customs identifiers.
 */
final class CustomsCodes
{
    /** MRN: 2 digits (year), 2 letters (country), 13 alphanumerics (procedure / serial) and 1 check character: 18 characters. */
    private const string MRN_PATTERN = '/^\d{2}[A-Z]{2}[A-Z0-9]{13}[A-Z0-9]$/';

    /** TARIC / combined nomenclature: 8 digits, 10 with the TARIC subdivision. */
    private const string TARIC_PATTERN = '/^\d{8}(\d{2})?$/';

    public static function normalizeMrn(string $mrn): string
    {
        return strtoupper(preg_replace('/\s+/', '', $mrn) ?? '');
    }

    public static function isValidMrn(string $mrn): bool
    {
        return preg_match(self::MRN_PATTERN, self::normalizeMrn($mrn)) === 1;
    }

    public static function isValidTaric(string $code): bool
    {
        return preg_match(self::TARIC_PATTERN, $code) === 1;
    }
}
