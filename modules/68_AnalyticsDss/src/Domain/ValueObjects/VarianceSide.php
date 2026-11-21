<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\ValueObjects;

/**
 * One side of a variance comparison (budget, prior period or actual) in the reporting currency: sold units, average unit
 * price, average unit cost of goods and operating expenses. The actual side is expressed at the exchange rates of the
 * baseline (constant currency); the FX translation impact is passed to the engine separately.
 */
final readonly class VarianceSide
{
    public function __construct(
        public float $units,
        public float $unitPrice,
        public float $unitCost,
        public float $opex,
    ) {}

    public function revenue(): float
    {
        return $this->units * $this->unitPrice;
    }

    public function cogs(): float
    {
        return $this->units * $this->unitCost;
    }

    public function ebitda(): float
    {
        return $this->revenue() - $this->cogs() - $this->opex;
    }
}
