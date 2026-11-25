<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Models\Garment;

/**
 * Runs every registered compliance strategy and merges their answers.
 */
final class ComplianceStrategyRegistry
{
    /**
     * @param  iterable<IndustrialLaundryComplianceStrategyInterface>  $strategies
     */
    public function __construct(private readonly iterable $strategies) {}

    /**
     * @return list<ComplianceFinding>
     */
    public function garmentFindings(Garment $garment, int $projectedWashCycles): array
    {
        $findings = [];

        foreach ($this->strategies as $strategy) {
            $findings = [...$findings, ...$strategy->evaluateGarment($garment, $projectedWashCycles)];
        }

        return $findings;
    }

    /**
     * @return list<ComplianceFinding>
     */
    public function batchFindings(WashBatchMeasurements $measurements): array
    {
        $findings = [];

        foreach ($this->strategies as $strategy) {
            $findings = [...$findings, ...$strategy->evaluateWashBatch($measurements)];
        }

        return $findings;
    }

    public function decorateBilling(BillingContext $context): BillingDecoration
    {
        $decoration = new BillingDecoration;

        foreach ($this->strategies as $strategy) {
            $decoration = $strategy->decorateBilling($context, $decoration);
        }

        return $decoration;
    }

    /**
     * @param  list<ComplianceFinding>  $findings
     */
    public static function blocks(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->isBlocking()) {
                return true;
            }
        }

        return false;
    }
}
