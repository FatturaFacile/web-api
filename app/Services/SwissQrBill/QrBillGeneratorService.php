<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

use App\Models\Invoice;
use InvalidArgumentException;

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

    /**
     * Generate Swiss QR-Bill data structure and SVG visual preview for an invoice.
     *
     * @return array{
     *     qr_iban: string,
     *     scor_reference: string,
     *     scor_formatted: string,
     *     currency: string,
     *     amount: float,
     *     rounding_amount: float,
     *     creditor_info: array<string, mixed>,
     *     debtor_info: array<string, mixed>,
     *     payload: string,
     *     svg: string
     * }
     *
     * @deprecated Fabricates default addresses and renders a placeholder QR. Use SwissQrBillService::generate() instead; the check-digit and IBAN helpers of this class remain in use.
     */
    public function generateForInvoice(Invoice $invoice, ?string $customQrIban = null): array
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;

        $qrIban = $customQrIban ?? $tenant->qr_iban ?? $tenant->iban;
        if (empty($qrIban)) {
            throw new InvalidArgumentException('Tenant has no configured QR-IBAN or IBAN.');
        }

        // Clean & format
        $cleanQrIban = strtoupper(preg_replace('/\s+/', '', $qrIban) ?? '');

        // Generate 27-digit SCOR
        $numericInvoiceSeed = preg_replace('/\D/', '', $invoice->invoice_number) ?: (string) crc32($invoice->id);
        $scorReference = $this->generateScorReference($numericInvoiceSeed);

        // Address resolution
        $tenantSettings = $tenant->settings ?? [];
        $creditor = [
            'name' => $tenant->legal_name ?: $tenant->name,
            'street' => $tenantSettings['street'] ?? 'Bahnhofstrasse',
            'house_number' => $tenantSettings['house_number'] ?? '1',
            'postal_code' => $tenantSettings['postal_code'] ?? '6900',
            'city' => $tenantSettings['city'] ?? 'Lugano',
            'country' => $tenant->country ?: 'CH',
        ];

        $billingAddress = $contact?->addresses()->where('type', 'billing')->first();
        $debtor = [
            'name' => $contact?->company_name ?: ($contact?->first_name.' '.$contact?->last_name),
            'street' => $billingAddress?->street ?? 'Via Nassa',
            'house_number' => $billingAddress?->house_number ?? '10',
            'postal_code' => $billingAddress?->postal_code ?? '6900',
            'city' => $billingAddress?->city ?? 'Lugano',
            'country' => $contact?->country ?: 'CH',
        ];

        $currency = $invoice->currency ?: 'CHF';
        $unroundedTotal = (float) $invoice->total_gross;
        $roundingAmount = 0.0;
        $finalAmount = $unroundedTotal;

        if ($currency === 'CHF') {
            $finalAmount = $this->roundChfAmount($unroundedTotal);
            $roundingAmount = $this->computeRoundingDifference($unroundedTotal);
        }

        $payload = $this->assembleQrPayload(
            qrIban: $cleanQrIban,
            creditor: $creditor,
            amount: $finalAmount,
            currency: $currency,
            debtor: $debtor,
            scorReference: $scorReference,
            unstructuredMessage: "Fattura {$invoice->invoice_number}"
        );

        $svg = $this->renderQrBillSvg(
            payload: $payload,
            qrIban: $cleanQrIban,
            creditor: $creditor,
            debtor: $debtor,
            amount: $finalAmount,
            currency: $currency,
            scorFormatted: $this->formatScorReference($scorReference)
        );

        return [
            'qr_iban' => $cleanQrIban,
            'scor_reference' => $scorReference,
            'scor_formatted' => $this->formatScorReference($scorReference),
            'currency' => $currency,
            'amount' => $finalAmount,
            'rounding_amount' => $roundingAmount,
            'creditor_info' => $creditor,
            'debtor_info' => $debtor,
            'payload' => $payload,
            'svg' => $svg,
        ];
    }

    /**
     * Render a clean Swiss QR-Bill payment part SVG according to official layout guidelines.
     *
     * @param  array<string, mixed>  $creditor
     * @param  array<string, mixed>  $debtor
     */
    private function renderQrBillSvg(
        string $payload,
        string $qrIban,
        array $creditor,
        array $debtor,
        float $amount,
        string $currency,
        string $scorFormatted
    ): string {
        $encodedPayload = htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $creditorName = htmlspecialchars($creditor['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $creditorAddr = htmlspecialchars(($creditor['street'] ?? '').' '.($creditor['house_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $creditorCity = htmlspecialchars(($creditor['postal_code'] ?? '').' '.($creditor['city'] ?? ''), ENT_QUOTES, 'UTF-8');

        $debtorName = htmlspecialchars($debtor['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $debtorAddr = htmlspecialchars(($debtor['street'] ?? '').' '.($debtor['house_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $debtorCity = htmlspecialchars(($debtor['postal_code'] ?? '').' '.($debtor['city'] ?? ''), ENT_QUOTES, 'UTF-8');

        $amountFormatted = number_format($amount, 2, '.', ' ');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 794 397" width="210mm" height="105mm" style="background:#fff;font-family:Arial,Helvetica,sans-serif;">
    <!-- Separation line -->
    <line x1="234" y1="0" x2="234" y2="397" stroke="#000" stroke-width="1" stroke-dasharray="4,4"/>
    <line x1="0" y1="0" x2="794" y2="0" stroke="#000" stroke-width="1" stroke-dasharray="4,4"/>

    <!-- Receipt Part -->
    <text x="20" y="30" font-size="14" font-weight="bold">Empfangsschein / Récépissé / Ricevuta</text>
    <text x="20" y="60" font-size="10" font-weight="bold">Konto / Compte / Conto</text>
    <text x="20" y="75" font-size="10">{$qrIban}</text>
    <text x="20" y="95" font-size="10" font-weight="bold">Empfänger / Bénéficiaire / Beneficiario</text>
    <text x="20" y="110" font-size="10">{$creditorName}</text>
    <text x="20" y="125" font-size="10">{$creditorAddr}</text>
    <text x="20" y="140" font-size="10">{$creditorCity}</text>
    <text x="20" y="170" font-size="10" font-weight="bold">Referenz / Référence / Riferimento</text>
    <text x="20" y="185" font-size="10">{$scorFormatted}</text>
    <text x="20" y="215" font-size="10" font-weight="bold">Zahlbar durch / Payable par / Pagabile da</text>
    <text x="20" y="230" font-size="10">{$debtorName}</text>
    <text x="20" y="245" font-size="10">{$debtorAddr}</text>
    <text x="20" y="260" font-size="10">{$debtorCity}</text>
    <text x="20" y="340" font-size="10" font-weight="bold">Währung / Monnaie / Valuta</text>
    <text x="20" y="360" font-size="12">{$currency}</text>
    <text x="90" y="340" font-size="10" font-weight="bold">Betrag / Montant / Importo</text>
    <text x="90" y="360" font-size="12">{$amountFormatted}</text>

    <!-- Payment Part -->
    <text x="254" y="30" font-size="16" font-weight="bold">Zahlteil / Section paiement / Sezione pagamento</text>
    <!-- Swiss QR Code Placeholder with Swiss Cross Box -->
    <rect x="254" y="55" width="174" height="174" fill="#f4f4f4" stroke="#000" stroke-width="1"/>
    <text x="264" y="80" font-size="8" fill="#555">QR-Payload: SPC 0200</text>
    <rect x="321" y="122" width="40" height="40" fill="#000"/>
    <rect x="337" y="130" width="8" height="24" fill="#fff"/>
    <rect x="329" y="138" width="24" height="8" fill="#fff"/>

    <!-- Payment details right column -->
    <text x="450" y="60" font-size="10" font-weight="bold">Konto / Compte / Conto</text>
    <text x="450" y="75" font-size="11">{$qrIban}</text>
    <text x="450" y="100" font-size="10" font-weight="bold">Empfänger / Bénéficiaire / Beneficiario</text>
    <text x="450" y="115" font-size="11">{$creditorName}</text>
    <text x="450" y="130" font-size="11">{$creditorAddr}</text>
    <text x="450" y="145" font-size="11">{$creditorCity}</text>
    <text x="450" y="175" font-size="10" font-weight="bold">Referenz / Référence / Riferimento</text>
    <text x="450" y="190" font-size="11" font-weight="bold">{$scorFormatted}</text>
    <text x="450" y="220" font-size="10" font-weight="bold">Zahlbar durch / Payable par / Pagabile da</text>
    <text x="450" y="235" font-size="11">{$debtorName}</text>
    <text x="450" y="250" font-size="11">{$debtorAddr}</text>
    <text x="450" y="265" font-size="11">{$debtorCity}</text>

    <!-- Bottom amounts in Payment Part -->
    <text x="254" y="340" font-size="10" font-weight="bold">Währung / Monnaie / Valuta</text>
    <text x="254" y="365" font-size="14" font-weight="bold">{$currency}</text>
    <text x="340" y="340" font-size="10" font-weight="bold">Betrag / Montant / Importo</text>
    <text x="340" y="365" font-size="14" font-weight="bold">{$amountFormatted}</text>
</svg>
SVG;
    }
}
