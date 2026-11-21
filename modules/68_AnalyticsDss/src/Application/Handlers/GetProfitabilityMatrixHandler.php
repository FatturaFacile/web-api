<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Modules\AnalyticsDss\Application\Queries\GetProfitabilityMatrixQuery;
use Modules\AnalyticsDss\Domain\Services\ProfitabilityMatrixCalculator;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;
use Modules\AnalyticsDss\Infrastructure\Support\DssCache;

/**
 * Profitability matrix of one dimension (customer, product, channel, cost center, legal entity) with the Pareto and Whale
 * Curve series.
 */
final class GetProfitabilityMatrixHandler
{
    public const array Dimensions = ['customer', 'product', 'channel', 'cost_center', 'legal_entity'];

    public function __construct(private readonly MartReader $reader, private readonly DssCache $cache) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetProfitabilityMatrixQuery $query): array
    {
        $filter = $query->filter;

        return $this->cache->remember($filter->tenantId, $filter->cacheKey("profitability:{$query->dimension}:{$query->limit}"), function () use ($query, $filter): array {
            $calculator = new ProfitabilityMatrixCalculator((float) config('analytics_dss.cost_of_capital_rate', 0.06));
            $result = $calculator->calculate($this->reader->breakdown($filter, $query->dimension, $filter->from, $filter->to));
            $rows = array_slice($result['rows'], 0, $query->limit);

            return [
                'dimension' => $query->dimension,
                'currency' => $filter->currency,
                'period' => ['from' => $filter->from, 'to' => $filter->to],
                'rows' => $rows,
                'whale_curve' => array_slice($result['whale_curve'], 0, $query->limit),
                'totals' => $result['totals'],
                'pareto_cutoff_rank' => $result['pareto_cutoff_rank'],
                'entity_count' => count($result['rows']),
            ];
        });
    }
}
