<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Formal validation of a fiscal identifier. `kind`: it_vat, it_fiscal_code, ch_uid, sdi_code.
 */
final class ValidFiscalIdentifier implements ValidationRule
{
    public function __construct(private readonly string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $valid = is_string($value) && match ($this->kind) {
            'it_vat' => FiscalIdentifiers::isValidItalianVat($value),
            'it_fiscal_code' => FiscalIdentifiers::isValidItalianFiscalCode($value),
            'ch_uid' => FiscalIdentifiers::isValidSwissUid($value),
            'sdi_code' => FiscalIdentifiers::isValidSdiCode($value),
            'eori' => FiscalIdentifiers::isValidEori($value),
            default => false,
        };

        if (! $valid) {
            $fail("The {$attribute} is not a formally valid ".match ($this->kind) {
                'it_vat' => 'Italian VAT number (Partita IVA).',
                'it_fiscal_code' => 'Italian fiscal code (Codice Fiscale).',
                'ch_uid' => 'Swiss UID (CHE-xxx.xxx.xxx).',
                'eori' => 'EORI number (e.g. IT12345678901, CHE123456788).',
                default => 'SDI recipient code (7 alphanumeric characters).',
            });
        }
    }
}
