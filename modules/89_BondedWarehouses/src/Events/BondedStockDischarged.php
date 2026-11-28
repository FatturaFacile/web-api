<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Events;

/**
 * Bonded stock was discharged (clearance, re-export, transit or usual handling); the guarantee share of the lots was released.
 */
final readonly class BondedStockDischarged
{
    /**
     * @param  list<string>  $movementIds
     */
    public function __construct(
        public string $authorizationId,
        public string $dischargeType,
        public array $movementIds,
        public float $cguReleased,
    ) {}
}
