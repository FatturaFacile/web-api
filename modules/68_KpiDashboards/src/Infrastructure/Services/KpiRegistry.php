<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Infrastructure\Calculators\ExecutiveKpiCalculator;
use Modules\KpiDashboards\Infrastructure\Calculators\FinanceKpiCalculator;
use Modules\KpiDashboards\Infrastructure\Calculators\HrKpiCalculator;
use Modules\KpiDashboards\Infrastructure\Calculators\KpiCalculator;
use Modules\KpiDashboards\Infrastructure\Calculators\OperationsKpiCalculator;
use Modules\KpiDashboards\Infrastructure\Calculators\SalesKpiCalculator;

/**
 * The calculator of every KPI of the catalog.
 */
final class KpiRegistry
{
    /** @var array<string, KpiCalculator> */
    private array $byKpi = [];

    public function __construct(
        ExecutiveKpiCalculator $executive,
        FinanceKpiCalculator $finance,
        SalesKpiCalculator $sales,
        OperationsKpiCalculator $operations,
        HrKpiCalculator $hr,
    ) {
        foreach ([$executive, $finance, $sales, $operations, $hr] as $calculator) {
            foreach ($calculator->supports() as $kpi) {
                $this->byKpi[$kpi->value] = $calculator;
            }
        }
    }

    public function calculator(KpiCode $kpi): KpiCalculator
    {
        return $this->byKpi[$kpi->value];
    }
}
