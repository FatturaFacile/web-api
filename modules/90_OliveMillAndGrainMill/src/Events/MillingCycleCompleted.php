<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Events;

/**
 * A milling cycle was completed: yield computed, oil routed to the farmer, the molenda and the mill tanks.
 */
final readonly class MillingCycleCompleted
{
    public function __construct(
        public string $cycleId,
        public string $deliveryId,
        public float $yieldPercent,
        public float $outputKg,
    ) {}
}
