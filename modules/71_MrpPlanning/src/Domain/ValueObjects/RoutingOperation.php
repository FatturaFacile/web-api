<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Modules\Manufacturing\Support\Money;

/**
 * A routing step: setup + run (per unit) + teardown minutes on a work centre (Mod 061).
 */
final readonly class RoutingOperation
{
    public function __construct(
        public int $sequence,
        public string $workCenterId,
        public string $setupMinutes,
        public string $runMinutesPerUnit,
        public string $teardownMinutes = '0',
    ) {}

    /**
     * Total minutes needed for the quantity, rounded up to whole minutes.
     */
    public function minutesFor(string $quantity): int
    {
        $total = Money::add(Money::add($this->setupMinutes, $this->teardownMinutes), Money::mul($this->runMinutesPerUnit, $quantity));

        return (int) Money::ceilToMultiple($total, '1');
    }
}
