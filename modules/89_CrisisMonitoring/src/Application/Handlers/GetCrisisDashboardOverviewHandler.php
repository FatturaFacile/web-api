<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Queries\GetCrisisDashboardOverviewQuery;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;

/**
 * The unified dashboard of the company: both regimes, computed live from the records.
 */
final class GetCrisisDashboardOverviewHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCrisisDashboardOverviewQuery $query): array
    {
        return $this->engine->evaluate($this->engine->load($query->tenant, $query->asOf));
    }
}
