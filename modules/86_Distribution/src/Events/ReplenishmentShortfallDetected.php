<?php

declare(strict_types=1);

namespace Modules\Distribution\Events;

/**
 * The supplying hub cannot cover a replenishment need (or there is no hub): Procurement / MRP planning can pick the quantity up
 * as a purchase or production requirement.
 */
final readonly class ReplenishmentShortfallDetected
{
    public function __construct(
        public string $tenantId,
        public string $warehouseId,
        public string $stockItemId,
        public string $missingQuantity,
    ) {}
}
