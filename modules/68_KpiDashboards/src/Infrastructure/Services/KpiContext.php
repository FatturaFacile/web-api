<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * What a calculator needs to build a payload: the filter, the access of the viewer and the FX rate to apply to money
 * (average rate for a flow KPI, closing rate for a stock KPI, decided by the handler per KPI).
 */
final readonly class KpiContext
{
    public function __construct(
        public CockpitFilter $filter,
        public KpiAccess $access,
        public float $fxRate,
    ) {}

    /**
     * An amount of the accounting currency in the display currency.
     */
    public function money(float $amount): float
    {
        return round($amount * $this->fxRate, 2);
    }
}
