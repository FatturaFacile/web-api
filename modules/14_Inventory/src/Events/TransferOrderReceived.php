<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

/** A transfer order was received for the last time (full receipt or final receipt with discrepancies). */
final readonly class TransferOrderReceived
{
    public function __construct(
        public string $transferOrderId,
        public string $tenantId,
        public bool $hasDiscrepancies,
    ) {}
}
