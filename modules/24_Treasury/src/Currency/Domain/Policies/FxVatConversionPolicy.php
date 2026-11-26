<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Policies;

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;

/**
 * How the VAT of a foreign-currency document is converted for the VAT return of a country (strategy).
 */
interface FxVatConversionPolicy
{
    public function country(): string;

    /**
     * Currency in which the VAT of the document is declared and shown on it.
     */
    public function vatCurrency(): string;

    /**
     * Rate type to resolve for the VAT of a document under a company method.
     */
    public function rateTypeFor(VatRateMethod $method): RateType;

    /**
     * The methods a company of the country may choose.
     *
     * @return list<VatRateMethod>
     */
    public function allowedMethods(): array;

    /**
     * Whether the company may switch from its current method now: Swiss law requires the method to be kept for at least one
     * tax period.
     */
    public function canChangeMethod(?CarbonImmutable $lastChange, CarbonImmutable $now): bool;

    /**
     * VAT of a document in the VAT currency, to the cent, half up.
     */
    public function convertVat(string $vatAmount, ResolvedRate $rate): string;
}
