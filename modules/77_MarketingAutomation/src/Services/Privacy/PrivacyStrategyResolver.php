<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Privacy;

use Modules\MarketingAutomation\Domain\Contracts\IMarketingPrivacyComplianceStrategy;

/**
 * Picks the strategy by jurisdiction: Swiss recipients follow LCSl/nLPD, every other one the stricter GDPR opt-in.
 */
final readonly class PrivacyStrategyResolver
{
    public function __construct(
        private ChLcslArt3OptInOutNlpdStrategy $ch,
        private ItGdprArt130CodicePrivacyConsensiStrategy $it,
    ) {}

    /**
     * @return list<IMarketingPrivacyComplianceStrategy>
     */
    public function all(): array
    {
        return [$this->ch, $this->it];
    }

    public function forJurisdiction(?string $contactCountry, ?string $tenantCountry = null): IMarketingPrivacyComplianceStrategy
    {
        $jurisdiction = strtoupper($contactCountry !== null && $contactCountry !== '' ? $contactCountry : ($tenantCountry ?? 'CH'));

        foreach ($this->all() as $strategy) {
            if ($strategy->supports($jurisdiction)) {
                return $strategy;
            }
        }

        return $this->it;
    }
}
