<?php

declare(strict_types=1);

namespace Modules\Logistics\Events;

final readonly class StockTransferShipped
{
    public function __construct(
        public string $transferId,
        public string $deliveryNoteId,
        public string $tenantId,
    ) {}
}
