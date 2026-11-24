<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

/**
 * Format and check digit of the company identifiers of the two countries of the module: the Italian VAT number
 * (partita IVA, 11 digits, Luhn-style check digit) and the Swiss enterprise identification number (UID, CHE-123.456.789,
 * modulo 11 check digit). It proves the number is well formed, not that the company exists: that is a supplier check.
 */
final class TaxIdentifierValidator
{
    /**
     * @param  string  $country  ISO 3166-1 alpha-2 code
     */
    public function isValid(string $country, string $value): bool
    {
        return match (strtoupper($country)) {
            'IT' => $this->isItalianVatNumber($value),
            'CH' => $this->isSwissUid($value),
            default => trim($value) !== '',
        };
    }

    public function isItalianVatNumber(string $value): bool
    {
        $digits = preg_replace('/^IT/i', '', preg_replace('/[\s.\-]/', '', $value) ?? '') ?? '';

        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (str_split($digits) as $index => $character) {
            $digit = (int) $character;

            if ($index % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public function isSwissUid(string $value): bool
    {
        $normalized = strtoupper(preg_replace('/[\s.\-]/', '', $value) ?? '');
        $normalized = preg_replace('/(MWST|TVA|IVA|VAT)$/', '', $normalized) ?? '';

        if (preg_match('/^CHE(\d{9})$/', $normalized, $matches) !== 1) {
            return false;
        }

        $digits = array_map('intval', str_split($matches[1]));
        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        $check = 11 - ($sum % 11);

        if ($check === 11) {
            $check = 0;
        }

        return $check !== 10 && $check === $digits[8];
    }
}
