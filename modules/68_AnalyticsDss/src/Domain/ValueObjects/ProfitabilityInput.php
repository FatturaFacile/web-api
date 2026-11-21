<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\ValueObjects;

/**
 * Revenue and cost figures of one entity (customer, product, channel, cost center or legal entity) in the reporting currency.
 */
final readonly class ProfitabilityInput
{
    public function __construct(
        public string $key,
        public string $label,
        public float $grossRevenue,
        public float $discounts,
        public float $cogs,
        public float $variableCosts,
        public float $directOpex,
        public float $allocatedOverhead,
        public float $depreciation = 0.0,
        public float $averagePaymentDelayDays = 0.0,
    ) {}
}
