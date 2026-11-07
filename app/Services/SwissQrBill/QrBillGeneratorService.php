<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

class QrBillGeneratorService
{
    /**
     * Round gross amount according to Swiss 5 centimes (0.05 CHF) rounding rule.
     */
    public function roundChfAmount(float $amount): float
    {
        return round($amount * 20.0) / 20.0;
    }

    /**
     * Compute the rounding difference (rounded - unrounded).
     */
    public function computeRoundingDifference(float $amount): float
    {
        $rounded = $this->roundChfAmount($amount);

        return round($rounded - $amount, 2);
    }

    /**
     * Validate whether an IBAN is a valid Swiss QR-IBAN.
     * In Switzerland, QR-IBANs are defined by an IID (positions 5-9) in the range 30000 - 31999.
     */
    public function isValidQrIban(string $iban): bool
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if (! preg_match('/^(CH|LI)[0-9]{2}(3[01][0-9]{3})[0-9A-Z]{12}$/', $clean, $matches)) {
            return false;
        }

        $iid = (int) $matches[2];
        if ($iid < 30000 || $iid > 31999) {
            return false;
        }

        return $this->validateIbanChecksum($clean);
    }

    /**
     * Standard MOD-97 IBAN checksum validation.
     */
    public function validateIbanChecksum(string $iban): bool
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (strlen($clean) < 15 || strlen($clean) > 34) {
            return false;
        }

        // Move first 4 characters to end and convert letters to numbers (A=10, B=11, ... Z=35)
        $reordered = substr($clean, 4).substr($clean, 0, 4);
        $numericString = '';

        for ($i = 0; $i < strlen($reordered); $i++) {
            $char = $reordered[$i];
            if (ctype_digit($char)) {
                $numericString .= $char;
            } elseif (ctype_alpha($char)) {
                $numericString .= (string) (ord($char) - 55);
            } else {
                return false;
            }
        }

        // Modulo 97 calculation on large numeric string
        $remainder = 0;
        $chunks = str_split($numericString, 7);
        foreach ($chunks as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }

    /**
     * Calculate Modulo 10 recursive check digit (Luhn-style table variant used by SIX Group).
     */
    public function calculateMod10RecursiveCheckDigit(string $input): int
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';
        $table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];
        $carry = 0;

        for ($i = 0; $i < strlen($digits); $i++) {
            $carry = $table[($carry + (int) $digits[$i]) % 10];
        }

        return (10 - $carry) % 10;
    }

    /**
     * Generate a full 27-digit Structured Creditor Reference (SCOR / QR-Reference)
     * using the Modulo 10 recursive checksum.
     */
    public function generateScorReference(string|int $rawReference): string
    {
        $digits = preg_replace('/\D/', '', (string) $rawReference) ?? '';

        if (strlen($digits) > 26) {
            $digits = substr($digits, -26);
        }

        // Pad to 26 digits with leading zeros
        $base = str_pad($digits, 26, '0', STR_PAD_LEFT);
        $checkDigit = $this->calculateMod10RecursiveCheckDigit($base);

        return $base.$checkDigit;
    }

    /**
     * Validate an existing 27-character SCOR reference against its Modulo 10 checksum.
     */
    public function validateScorReference(string $reference): bool
    {
        $clean = preg_replace('/\s+/', '', $reference) ?? '';
        if (strlen($clean) !== 27 || ! ctype_digit($clean)) {
            return false;
        }

        $base = substr($clean, 0, 26);
        $expectedChecksum = (int) substr($clean, 26, 1);

        return $this->calculateMod10RecursiveCheckDigit($base) === $expectedChecksum;
    }

    /**
     * Format a 27-digit SCOR reference into 5-character readable blocks.
     * e.g. "21 00000 00003 13947 14300 09017"
     */
    public function formatScorReference(string $reference): string
    {
        $clean = preg_replace('/\s+/', '', $reference) ?? '';
        $parts = [];
        $parts[] = substr($clean, 0, 2);
        for ($i = 2; $i < 27; $i += 5) {
            $parts[] = substr($clean, $i, 5);
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Assemble the standard Swiss QR Code raw text payload according to SIX Swiss Payment Guidelines v2.2.
     *
     * @param array{
     *     name: string,
     *     street: ?string,
     *     house_number: ?string,
     *     postal_code: string,
     *     city: string,
     *     country: string
     * } $creditor
     * @param array{
     *     name: string,
     *     street: ?string,
     *     house_number: ?string,
     *     postal_code: string,
     *     city: string,
     *     country: string
     * } $debtor
     */
    public function assembleQrPayload(
        string $qrIban,
        array $creditor,
        float $amount,
        string $currency,
        array $debtor,
        string $scorReference,
        ?string $unstructuredMessage = null
    ): string {
        $cleanQrIban = strtoupper(preg_replace('/\s+/', '', $qrIban) ?? '');
        $roundedAmount = ($currency === 'CHF') ? $this->roundChfAmount($amount) : round($amount, 2);
        $amountStr = number_format($roundedAmount, 2, '.', '');

        $lines = [
            // Header
            'SPC',    // QRType
            '0200',   // Version
            '1',      // Coding Type (Latin character set)
            // Creditor information
            $cleanQrIban,
            'S',      // Structured address
            mb_substr($creditor['name'], 0, 70),
            mb_substr($creditor['street'] ?? '', 0, 70),
            mb_substr($creditor['house_number'] ?? '', 0, 16),
            mb_substr($creditor['postal_code'], 0, 16),
            mb_substr($creditor['city'], 0, 35),
            strtoupper(substr($creditor['country'] ?? 'CH', 0, 2)),
            // Ultimate Creditor (blank lines when not applicable)
            '', '', '', '', '', '', '',
            // Payment Amount & Currency
            $amountStr,
            strtoupper(substr($currency, 0, 3)),
            // Ultimate Debtor information
            'S',      // Structured address
            mb_substr($debtor['name'], 0, 70),
            mb_substr($debtor['street'] ?? '', 0, 70),
            mb_substr($debtor['house_number'] ?? '', 0, 16),
            mb_substr($debtor['postal_code'], 0, 16),
            mb_substr($debtor['city'], 0, 35),
            strtoupper(substr($debtor['country'] ?? 'CH', 0, 2)),
            // Payment Reference
            'QRR',    // Reference type: QRR (QR Reference / SCOR)
            $scorReference,
            // Unstructured message
            mb_substr($unstructuredMessage ?? '', 0, 140),
            // Trailer
            'EPD',    // End of Payment Data
        ];

        return implode("\r\n", $lines);
    }
}
