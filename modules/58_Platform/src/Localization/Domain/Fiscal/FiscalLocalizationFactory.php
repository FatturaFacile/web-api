<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Fiscal;

/**
 * Picks the fiscal localization of a country; countries without a dedicated strategy get none.
 */
final class FiscalLocalizationFactory
{
    public function forCountry(?string $country): ?FiscalLocalizationStrategy
    {
        return match (strtoupper((string) $country)) {
            'CH' => new SwissFiscalLocalization,
            'IT' => new ItalianFiscalLocalization,
            default => null,
        };
    }
}
