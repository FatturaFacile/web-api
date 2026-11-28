<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Events;

/**
 * A milling cycle was settled: service invoice, purchase document of the retained oil and SIAN load recorded.
 */
final readonly class MillingSettled
{
    public function __construct(
        public string $settlementId,
        public string $cycleId,
        public float $retainedKg,
        public float $cashBalance,
    ) {}
}
