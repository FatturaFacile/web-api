<?php

declare(strict_types=1);

namespace Modules\Customs\Support;

/**
 * Movement Reference Number. The Union form (AIDA, NCTS) has 18 characters: year (2 digits), country (2 letters),
 * 13 alphanumerics and a check character. Swiss systems issue longer numbers (up to 21 characters, the size the
 * declaration table keeps), so a Swiss MRN is accepted as 18 to 21 uppercase alphanumerics.
 */
final class Mrn
{
    private const string UNION_PATTERN = '/^\d{2}[A-Z]{2}[A-Z0-9]{13}[A-Z0-9]$/';

    private const string SWISS_PATTERN = '/^[A-Z0-9]{18,21}$/';

    public static function normalize(string $mrn): string
    {
        return strtoupper(trim($mrn));
    }

    public static function isUnion(string $mrn): bool
    {
        return preg_match(self::UNION_PATTERN, self::normalize($mrn)) === 1;
    }

    public static function isValid(string $mrn, bool $unionOnly = false): bool
    {
        return $unionOnly ? self::isUnion($mrn) : preg_match(self::SWISS_PATTERN, self::normalize($mrn)) === 1;
    }

    /**
     * A well-formed Union MRN for a sandbox answer, never one customs issued.
     */
    public static function simulated(string $country = 'IT'): string
    {
        return substr(date('Y'), 2).strtoupper($country).strtoupper(substr(bin2hex(random_bytes(8)), 0, 14));
    }
}
