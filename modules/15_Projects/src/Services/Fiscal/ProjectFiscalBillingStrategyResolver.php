<?php

declare(strict_types=1);

namespace Modules\Projects\Services\Fiscal;

use Modules\Projects\Contracts\ProjectFiscalBillingStrategyInterface;
use Modules\Projects\Models\ProjectBillingContract;

/**
 * Picks the billing strategy of a contract from its fiscal jurisdiction; unknown jurisdictions fall back to Italy, the
 * home market of the platform.
 */
final readonly class ProjectFiscalBillingStrategyResolver
{
    public function __construct(
        private ItProjectFiscalBillingStrategy $italy,
        private ChProjectFiscalBillingStrategy $switzerland,
    ) {}

    public function forContract(ProjectBillingContract $contract): ProjectFiscalBillingStrategyInterface
    {
        return $this->forJurisdiction($contract->fiscal_jurisdiction);
    }

    public function forJurisdiction(string $jurisdiction): ProjectFiscalBillingStrategyInterface
    {
        return strtoupper($jurisdiction) === 'CH' ? $this->switzerland : $this->italy;
    }

    /**
     * Jurisdiction implied by the billing currency when the contract does not state one.
     */
    public function defaultJurisdictionForCurrency(string $currency): string
    {
        return strtoupper($currency) === 'CHF' ? 'CH' : 'IT';
    }
}
