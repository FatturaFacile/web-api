<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Policies;

/**
 * The VAT conversion strategy of a country: Switzerland and Liechtenstein follow the Swiss rules, every other country the
 * Italian ones (the ECB reference rate of the document date).
 */
final class FxVatPolicyResolver
{
    public function forCountry(?string $country): FxVatConversionPolicy
    {
        return in_array(strtoupper((string) $country), ['CH', 'LI'], true) ? new SwissFxVatPolicy : new ItalianFxVatPolicy;
    }
}
