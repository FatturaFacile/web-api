<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;

/**
 * Computes the KPIs of one role cockpit. `headline` is the single comparable number (used for the prior period and the
 * threshold alerts) in the accounting currency; `payload` is the full widget data, its amounts converted by the context.
 */
interface KpiCalculator
{
    /**
     * @return list<KpiCode>
     */
    public function supports(): array;

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float;

    /**
     * @return array<string, mixed>
     */
    public function payload(KpiCode $kpi, KpiContext $context): array;
}
