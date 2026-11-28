<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Events;

/**
 * Goods entered a customs warehouse under an MRN and the guarantee was engaged.
 */
final readonly class BondedMrnLotRegistered
{
    public function __construct(
        public string $lotId,
        public string $authorizationId,
        public string $mrnNumber,
        public float $cguEngaged,
    ) {}
}
