<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Strategies;

use Modules\StatutoryFinancials\Contracts\IStatutoryReportingComplianceStrategy;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;

/**
 * Picks the compliance strategy of an accounting standard.
 */
final class StatutoryStrategyResolver
{
    /**
     * @param  iterable<IStatutoryReportingComplianceStrategy>  $strategies
     */
    public function __construct(private readonly iterable $strategies) {}

    public function for(AccountingStandard $standard): IStatutoryReportingComplianceStrategy
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($standard)) {
                return $strategy;
            }
        }

        throw new StatutoryFinancialsException('unsupported_standard', 422, ['standard' => $standard->value]);
    }
}
