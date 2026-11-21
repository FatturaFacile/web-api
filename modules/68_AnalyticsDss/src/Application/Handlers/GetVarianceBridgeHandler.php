<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Modules\AnalyticsDss\Application\Queries\GetVarianceBridgeQuery;
use Modules\AnalyticsDss\Domain\Services\VarianceDecompositionEngine;
use Modules\AnalyticsDss\Domain\ValueObjects\VarianceSide;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;
use Modules\AnalyticsDss\Infrastructure\Support\DssCache;

/**
 * EBITDA and revenue waterfall: baseline (prior period, prior year or budget) to actual through volume, price, cost, opex
 * and FX translation effects. Units come from the invoice lines; when either side has none the bridge falls back to one
 * notional unit, so the whole revenue effect is a price effect (`volume_basis` tells the client which case applies). A budget
 * carries no units: its volume is the actual one.
 */
final class GetVarianceBridgeHandler
{
    public function __construct(
        private readonly ExecutiveFigures $figures,
        private readonly MartReader $reader,
        private readonly VarianceDecompositionEngine $engine,
        private readonly DssCache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetVarianceBridgeQuery $query): array
    {
        $filter = $query->filter;

        return $this->cache->remember($filter->tenantId, $filter->cacheKey('variance'), function () use ($filter): array {
            [$priorFrom, $priorTo] = $filter->comparisonRange();
            $isBudget = $filter->comparisonBaseline === 'BUDGET';
            $actual = $this->figures->period($filter, $filter->from, $filter->to);
            $baseline = $isBudget ? $this->figures->budget($filter) : $this->figures->period($filter, $priorFrom, $priorTo);
            $fx = $isBudget ? ['revenue' => 0.0, 'ebitda' => 0.0] : $this->reader->fxTranslation($filter, $filter->from, $filter->to, $priorTo);

            $actualUnits = $this->reader->commercial($filter, $filter->from, $filter->to)['units'];
            $baselineUnits = $isBudget ? $actualUnits : $this->reader->commercial($filter, $priorFrom, $priorTo)['units'];
            $unitBased = $actualUnits > 0.0 && $baselineUnits > 0.0;
            [$actualUnits, $baselineUnits] = $unitBased ? [$actualUnits, $baselineUnits] : [1.0, 1.0];

            // The actual side is at constant currency: reported figures minus the translation effect. Cost is split by its reported mix.
            $revenueConstant = $actual['revenue'] - $fx['revenue'];
            $costConstant = $revenueConstant - ($actual['ebitda'] - $fx['ebitda']);
            $costReported = $actual['cogs'] + $actual['opex'];
            $cogsConstant = $costReported != 0.0 ? $costConstant * $actual['cogs'] / $costReported : 0.0;
            $opexConstant = $costConstant - $cogsConstant;

            $result = $this->engine->decompose(
                new VarianceSide($baselineUnits, $baseline['revenue'] / $baselineUnits, $baseline['cogs'] / $baselineUnits, $baseline['opex']),
                new VarianceSide($actualUnits, $revenueConstant / $actualUnits, $cogsConstant / $actualUnits, $opexConstant),
                $fx['ebitda'],
                $fx['revenue'],
            );

            return [
                'currency' => $filter->currency,
                'baseline' => ['type' => $filter->comparisonBaseline, 'from' => $priorFrom, 'to' => $priorTo, 'budget_found' => $isBudget ? (bool) $baseline['budget_found'] : null],
                'volume_basis' => $unitBased ? 'invoice_items' : 'unit_fallback',
                'ebitda_bridge' => $result['ebitda_bridge'],
                'revenue_bridge' => $result['revenue_bridge'],
                'summary' => $result['summary'],
                'line_variances' => [
                    'revenue' => $this->engine->delta($baseline['revenue'], $actual['revenue']),
                    'cogs' => $this->engine->delta($baseline['cogs'], $actual['cogs']),
                    'opex' => $this->engine->delta($baseline['opex'], $actual['opex']),
                    'ebitda' => $this->engine->delta($baseline['ebitda'], $actual['ebitda']),
                ],
            ];
        });
    }
}
