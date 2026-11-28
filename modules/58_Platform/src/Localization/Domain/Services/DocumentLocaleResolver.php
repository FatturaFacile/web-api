<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Services;

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

/**
 * The language a document is printed in: the locale frozen on the document, else the document locale of the contact,
 * else the document locale of the issuing company.
 */
final class DocumentLocaleResolver
{
    public function __construct(private readonly LocaleResolver $localeResolver = new LocaleResolver) {}

    public function resolve(?string $frozenLocale, ?string $contactDocumentLocale, string|SupportedLocale $issuingCompanyLocale, ?string $companyCountry = null): SupportedLocale
    {
        $region = $companyCountry !== null ? strtoupper($companyCountry) : null;

        return $this->localeResolver->fromPreference($frozenLocale, $region)
            ?? $this->localeResolver->fromPreference($contactDocumentLocale, $region)
            ?? ($issuingCompanyLocale instanceof SupportedLocale ? $issuingCompanyLocale : $this->localeResolver->fromPreference($issuingCompanyLocale, $region))
            ?? SupportedLocale::En;
    }
}
