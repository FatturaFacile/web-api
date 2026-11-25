<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use App\Services\Tenancy\TenantContext;
use Modules\PassengerTransport\Domain\Enums\Jurisdiction;
use Modules\PassengerTransport\Domain\Strategies\ChOlr1Olr2BusMwStStrategy;
use Modules\PassengerTransport\Domain\Strategies\IPassengerTransportRegulatoryStrategy;
use Modules\PassengerTransport\Domain\Strategies\ItNccFoglioServizioArt7QuaterIvaStrategy;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;

/**
 * Picks the regulatory strategy from the fiscal country of the company: Switzerland uses the OLR / MWST rules, every other company
 * the Italian NCC and DPR 633/72 rules.
 */
final class RegulatoryStrategyResolver
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function forCurrentTenant(): IPassengerTransportRegulatoryStrategy
    {
        return $this->forCountry((string) $this->tenantContext->getTenant()?->country);
    }

    public function forCountry(string $country): IPassengerTransportRegulatoryStrategy
    {
        $driving = new DrivingTimeEvaluator(config('passenger_transport.olr2'));

        return strtoupper($country) === Jurisdiction::Ch->value
            ? new ChOlr1Olr2BusMwStStrategy((string) config('passenger_transport.vat.ch_standard_rate'), $driving)
            : new ItNccFoglioServizioArt7QuaterIvaStrategy(
                (string) config('passenger_transport.vat.it_rate'),
                (string) config('passenger_transport.vat.it_foreign_nature'),
                (string) config('passenger_transport.vat.it_urban_perimeter_km'),
                $driving,
            );
    }

    public function jurisdictionOfCurrentTenant(): Jurisdiction
    {
        return strtoupper((string) $this->tenantContext->getTenant()?->country) === Jurisdiction::Ch->value ? Jurisdiction::Ch : Jurisdiction::It;
    }
}
