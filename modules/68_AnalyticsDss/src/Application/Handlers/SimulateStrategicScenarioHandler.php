<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Modules\AnalyticsDss\Application\Queries\SimulateStrategicScenarioQuery;
use Modules\AnalyticsDss\Domain\Services\WhatIfScenarioSimulator;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;

/**
 * What-if simulation on the actuals of the filter period. Not cached: the answer depends on the scenario sliders. The
 * baseline block is returned too, so offline clients can run the same formulas locally.
 */
final class SimulateStrategicScenarioHandler
{
    public function __construct(private readonly ExecutiveFigures $figures, private readonly MartReader $reader, private readonly WhatIfScenarioSimulator $simulator) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(SimulateStrategicScenarioQuery $query): array
    {
        $filter = $query->filter;
        $actual = $this->figures->period($filter, $filter->from, $filter->to);
        $shares = $this->reader->foreignShares($filter, $filter->from, $filter->to);
        $fte = $this->reader->fte($filter, $filter->from, $filter->to);

        $baseline = [
            'revenue' => $actual['revenue'], 'cogs' => $actual['cogs'], 'opex' => $actual['opex'],
            'dso_days' => (float) $actual['working_capital']['dso_days'], 'net_cash_flow' => $actual['net_cash_flow'], 'period_days' => $actual['days'],
            'fte_annual_cost' => $fte['annual_cost_per_fte'] ?? 0.0, 'revenue_fx_share' => $shares['revenue'], 'cost_fx_share' => $shares['cost'],
        ];

        return [
            'currency' => $filter->currency,
            'period' => ['from' => $filter->from, 'to' => $filter->to],
            'inputs' => $baseline,
            'scenario' => $query->scenario,
            ...$this->simulator->simulate($baseline, $query->scenario),
        ];
    }
}
