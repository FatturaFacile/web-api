<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Compliance;

/**
 * Picks the compliance strategy of a jurisdiction. A country other than Switzerland follows the Italian rules.
 */
final class ComplianceStrategyResolver
{
    public const array JURISDICTIONS = ['IT', 'CH'];

    public function for(string $jurisdiction): IGreenCareComplianceStrategy
    {
        return strtoupper($jurisdiction) === 'CH' ? new ChJardinSuisseUfamStrategy : new ItQuadernoCampagnaCerStrategy;
    }

    public function normalize(?string $country): string
    {
        return strtoupper((string) $country) === 'CH' ? 'CH' : 'IT';
    }
}
