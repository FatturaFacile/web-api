<?php

declare(strict_types=1);

namespace Modules\Logistics\Events;

final readonly class StockTransferReceived
{
    public function __construct(
        public string $transferId,
        public string $tenantId,
        public bool $hasDiscrepancies,
    ) {}
}
