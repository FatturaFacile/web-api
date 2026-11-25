<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class TechnicalCpqConfiguredEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $configurationId,
        public bool $hasConstraintConflict,
        public float $calculatedMarginPct,
    ) {}
}
