<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

interface LeadNormalizerInterface
{
    public function normalizeEmail(?string $email): ?string;

    /**
     * Converts a phone number to E.164 (e.g. +393331234567); null when it cannot be interpreted.
     */
    public function normalizePhone(?string $phone, ?string $defaultCountry = null): ?string;

    /**
     * Upper-cases a VAT / UID number and strips spaces and punctuation.
     */
    public function normalizeVat(?string $vat): ?string;

    /**
     * Equivalent spellings of a normalized VAT number (with and without the IT prefix).
     *
     * @return list<string>
     */
    public function vatVariants(?string $vat): array;

    /**
     * Lower-cases a company name and strips accents, punctuation and legal-form suffixes.
     */
    public function normalizeCompanyName(?string $companyName): ?string;
}
