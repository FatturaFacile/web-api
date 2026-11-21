<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Modules\ShiftPlanning\Domain\Contracts\LaborComplianceStrategyInterface;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;

/**
 * Picks the strategy of the establishment. The strategies are tagged in the container, so a collective agreement (CCNL, CCL)
 * adds a strategy for a jurisdiction by tagging a subclass; the last strategy registered for a jurisdiction wins.
 */
final class LaborComplianceStrategyResolver
{
    /**
     * @param  iterable<LaborComplianceStrategyInterface>  $strategies
     */
    public function __construct(private readonly iterable $strategies) {}

    public function for(Jurisdiction $jurisdiction): LaborComplianceStrategyInterface
    {
        $found = null;

        foreach ($this->strategies as $strategy) {
            if ($strategy->jurisdiction() === $jurisdiction) {
                $found = $strategy;
            }
        }

        return $found ?? throw new ShiftPlanningException("No labour compliance strategy for [{$jurisdiction->value}].");
    }
}
