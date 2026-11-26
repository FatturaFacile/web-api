<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Policies;

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;
use Modules\Treasury\Currency\Domain\Support\Dec;

/**
 * Switzerland (Art. 45 OIVA, Info IVA 16): the VAT of a document in a foreign currency is declared in CHF, at the ESTV monthly
 * average rate or at the daily rate, the same method for at least one tax period (here a quarter), and shown in CHF on the
 * document.
 */
final class SwissFxVatPolicy implements FxVatConversionPolicy
{
    public const int MINIMUM_RETENTION_MONTHS = 3;

    public function country(): string
    {
        return 'CH';
    }

    public function vatCurrency(): string
    {
        return 'CHF';
    }

    public function rateTypeFor(VatRateMethod $method): RateType
    {
        return $method === VatRateMethod::EstvMonthly ? RateType::VatEstvMonthly : RateType::Mid;
    }

    public function allowedMethods(): array
    {
        return [VatRateMethod::EstvMonthly, VatRateMethod::Daily];
    }

    public function canChangeMethod(?CarbonImmutable $lastChange, CarbonImmutable $now): bool
    {
        return $lastChange === null || $lastChange->addMonthsNoOverflow(self::MINIMUM_RETENTION_MONTHS)->lessThanOrEqualTo($now);
    }

    public function convertVat(string $vatAmount, ResolvedRate $rate): string
    {
        return Dec::money(Dec::mul($vatAmount, $rate->rate->value));
    }
}
