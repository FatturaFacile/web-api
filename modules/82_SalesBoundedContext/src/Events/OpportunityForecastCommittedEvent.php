<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class OpportunityForecastCommittedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $opportunityId,
        public string $stage,
        public string $weightedAmount,
        public string $forecastCategory,
    ) {}
}
