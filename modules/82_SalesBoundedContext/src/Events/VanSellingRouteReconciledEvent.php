<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class VanSellingRouteReconciledEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $routeId,
        public string $reconciliationStatus,
        public float $cashDiscrepancyAmount,
    ) {}
}
