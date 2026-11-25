<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\ValueObjects;

use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;

/**
 * Cross-border CH/IT fiscal and electronic invoicing identity validator & normalizer.
 * Handles Swiss UID (CHE-xxx.xxx.xxx) and QR-IBAN (IID 30000-31999) as well as
 * Italian Partita IVA, Codice Fiscale, SDI recipient code, and PEC address.
 */
final readonly class FiscalIdentity
{
    public function __construct(
        public string $country,
        public ?string $uidNumber = null,
        public ?string $vatNumber = null,
        public ?string $fiscalCode = null,
        public ?string $sdiCode = null,
        public ?string $pecEmail = null,
        public ?string $qrIban = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $country = strtoupper(trim((string) ($data['country'] ?? 'CH')));
        $uid = isset($data['uid_number']) && $data['uid_number'] !== '' ? self::normalizeUid((string) $data['uid_number']) : null;
        $vat = isset($data['vat_number']) && $data['vat_number'] !== '' ? strtoupper(preg_replace('/\s+/', '', (string) $data['vat_number']) ?? '') : null;
        $cf = isset($data['fiscal_code']) && $data['fiscal_code'] !== '' ? strtoupper(trim((string) $data['fiscal_code'])) : null;
        $sdi = isset($data['sdi_code']) && $data['sdi_code'] !== '' ? strtoupper(trim((string) $data['sdi_code'])) : null;
        $pec = isset($data['pec_email']) && $data['pec_email'] !== '' ? strtolower(trim((string) $data['pec_email'])) : null;
        $qrIban = isset($data['qr_iban']) && $data['qr_iban'] !== '' ? strtoupper(preg_replace('/\s+/', '', (string) $data['qr_iban']) ?? '') : null;

        if ($uid !== null && ! self::isValidSwissUid($uid)) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid Swiss UID format [{$uid}]. Expected CHE-xxx.xxx.xxx.");
        }

        if ($vat !== null && $country === 'IT' && ! self::isValidItalianVat($vat)) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid Italian Partita IVA [{$vat}].");
        }

        if ($cf !== null && ! self::isValidItalianFiscalCode($cf)) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid Italian Codice Fiscale [{$cf}].");
        }

        if ($sdi !== null && preg_match('/^[A-Z0-9]{7}$/', $sdi) !== 1) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid SDI recipient code [{$sdi}]. Must be 7 alphanumeric characters.");
        }

        if ($pec !== null && filter_var($pec, FILTER_VALIDATE_EMAIL) === false) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid PEC email [{$pec}].");
        }

        if ($qrIban !== null && ! self::isValidSwissQrIban($qrIban)) {
            throw SalesBoundedContextException::invalidFiscalIdentity("Invalid Swiss QR-IBAN [{$qrIban}].");
        }

        return new self($country, $uid, $vat, $cf, $sdi, $pec, $qrIban);
    }

    public static function normalizeUid(string $raw): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
        if (str_starts_with($clean, 'CHE') && strlen($clean) >= 12) {
            $digits = substr($clean, 3, 9);

            return sprintf('CHE-%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3));
        }

        return strtoupper(trim($raw));
    }

    public static function isValidSwissUid(string $uid): bool
    {
        if (preg_match('/^CHE-(\d{3})\.(\d{3})\.(\d{3})(?:\s?(?:MWST|TVA|IVA))?$/', $uid, $matches) !== 1) {
            return false;
        }

        $digits = $matches[1].$matches[2].$matches[3];
        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += ((int) $digits[$i]) * $weights[$i];
        }
        $check = 11 - ($sum % 11);
        if ($check === 11) {
            $check = 0;
        }

        return $check !== 10 && $check === (int) $digits[8];
    }

    public static function isValidItalianVat(string $vat): bool
    {
        $digits = str_starts_with($vat, 'IT') ? substr($vat, 2) : $vat;
        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $val = (int) $digits[$i];
            if ($i % 2 === 1) {
                $val *= 2;
                if ($val > 9) {
                    $val -= 9;
                }
            }
            $sum += $val;
        }
        $expected = (10 - ($sum % 10)) % 10;

        return $expected === (int) $digits[10];
    }

    public static function isValidItalianFiscalCode(string $cf): bool
    {
        return preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/', $cf) === 1
            || preg_match('/^\d{11}$/', $cf) === 1;
    }

    public static function isValidSwissQrIban(string $iban): bool
    {
        if (preg_match('/^(CH|LI)\d{2}(\d{5})[A-Z0-9]{12}$/', $iban, $matches) !== 1) {
            return false;
        }

        $iid = (int) $matches[2];

        return $iid >= 30000 && $iid <= 31999;
    }

    /**
     * Generates a 27-digit Swiss QR-Reference (Mod10 recursive check digit).
     */
    public static function buildSwissQrReference(string $seedNumeric): string
    {
        $base = str_pad(substr(preg_replace('/\D/', '', $seedNumeric) ?? '1', 0, 26), 26, '0', STR_PAD_LEFT);
        $table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];
        $carry = 0;
        for ($i = 0; $i < strlen($base); $i++) {
            $carry = $table[($carry + (int) $base[$i]) % 10];
        }
        $check = (10 - $carry) % 10;

        return $base.(string) $check;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'uid_number' => $this->uidNumber,
            'vat_number' => $this->vatNumber,
            'fiscal_code' => $this->fiscalCode,
            'sdi_code' => $this->sdiCode,
            'pec_email' => $this->pecEmail,
            'qr_iban' => $this->qrIban,
        ];
    }
}
