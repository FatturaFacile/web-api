<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\ValueObjects;

use Modules\SwissVat\Exceptions\SwissVatException;

/**
 * Swiss UID / MWST taxpayer identifier (e.g. CHE-123.456.789 MWST).
 */
final readonly class SwissUidMwst
{
    public function __construct(public string $formatted, public string $digits) {}

    public static function parse(string $raw): self
    {
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (strlen($digits) !== 9) {
            throw SwissVatException::invalid('uid_mwst_invalid', ['uid' => $raw]);
        }

        if (! self::hasValidMod11CheckDigit($digits)) {
            throw SwissVatException::invalid('uid_mwst_checksum_invalid', ['uid' => $raw]);
        }

        $formatted = sprintf(
            'CHE-%s.%s.%s MWST',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 3),
        );

        return new self($formatted, $digits);
    }

    public static function normalizeOrFallback(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return 'CHE-000.000.000 MWST';
        }

        $digits = (string) preg_replace('/\D/', '', $raw);
        if (strlen($digits) !== 9) {
            return 'CHE-000.000.000 MWST';
        }

        return sprintf(
            'CHE-%s.%s.%s MWST',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 3),
        );
    }

    /**
     * Swiss UID Mod-11 check digit weights [5,4,3,2,7,6,5,4] per eCH-0097.
     */
    public static function hasValidMod11CheckDigit(string $digits): bool
    {
        if (strlen($digits) !== 9 || $digits === '000000000') {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += ((int) $digits[$i]) * $weights[$i];
        }

        $remainder = $sum % 11;
        $expected = (11 - $remainder) % 11;

        if ($expected === 10) {
            return false;
        }

        return $expected === (int) $digits[8];
    }

    public function compactUid(): string
    {
        return 'CHE'.$this->digits;
    }
}
