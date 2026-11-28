<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Queries;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The unified crisis dashboard: the Italian CCII view and the Swiss Art. 725 CO view of the company, the one of its jurisdiction first.
 */
final readonly class GetCrisisDashboardOverviewQuery
{
    public function __construct(
        public Tenant $tenant,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
