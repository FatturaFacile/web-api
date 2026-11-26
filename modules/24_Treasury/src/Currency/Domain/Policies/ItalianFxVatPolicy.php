<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Policies;

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;
use Modules\Treasury\Currency\Domain\Support\Dec;

/**
 * Italy (Art. 13 c. 4 DPR 633/72): conversion at the rate of the transaction or invoice date, or the closest preceding one, from
 * the ECB reference rates (Banca d'Italia); the FatturaPA states the currency (<Divisa>, 2.1.1.2) and the VAT also in EUR for
 * the VAT registers.
 */
final class ItalianFxVatPolicy implements FxVatConversionPolicy
{
    public function country(): string
    {
        return 'IT';
    }

    public function vatCurrency(): string
    {
        return 'EUR';
    }

    public function rateTypeFor(VatRateMethod $method): RateType
    {
        return RateType::Mid;
    }

    public function allowedMethods(): array
    {
        return [VatRateMethod::Ecb, VatRateMethod::Daily];
    }

    public function canChangeMethod(?CarbonImmutable $lastChange, CarbonImmutable $now): bool
    {
        return true;
    }

    public function convertVat(string $vatAmount, ResolvedRate $rate): string
    {
        return Dec::money(Dec::mul($vatAmount, $rate->rate->value));
    }
}
