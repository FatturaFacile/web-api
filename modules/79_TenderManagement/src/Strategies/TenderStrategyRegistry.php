<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Strategies;

use Modules\TenderManagement\Contracts\TenderProcurementComplianceStrategyInterface;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;

/**
 * Resolves the compliance strategy of a jurisdiction.
 */
final class TenderStrategyRegistry
{
    /**
     * @var list<TenderProcurementComplianceStrategyInterface>
     */
    private array $strategies;

    public function __construct(
        ChSimapLapubCiapStrategy $swiss,
        ItDlgs36CodiceAppaltiAnacMepaStrategy $italian,
        GenericTenderStrategy $generic,
    ) {
        $this->strategies = [$swiss, $italian, $generic];
    }

    public function for(Jurisdiction $jurisdiction): TenderProcurementComplianceStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($jurisdiction)) {
                return $strategy;
            }
        }

        return $this->strategies[2];
    }

    /**
     * @return list<TenderProcurementComplianceStrategyInterface>
     */
    public function all(): array
    {
        return $this->strategies;
    }
}
