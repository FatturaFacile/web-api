<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Commands;

/**
 * Drops the pre-aggregated KPI payloads of the tenant so the next cockpit load recomputes them. Debounced.
 */
final readonly class RefreshAnalyticalCubesCommand
{
    public function __construct(public string $tenantId) {}
}
