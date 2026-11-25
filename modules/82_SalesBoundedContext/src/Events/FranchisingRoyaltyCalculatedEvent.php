<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class FranchisingRoyaltyCalculatedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $affiliateId,
        public float $totalFeeInvoiceable,
        public float $benchmarkIndexPct,
    ) {}
}
