<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use Modules\Analytics\Pivot\DesignedReportPlan;
use Modules\Analytics\Pivot\PivotDefinition;
use Modules\Analytics\Pivot\PivotService;

/**
 * Validates the definition against the permissions of the caller, executes the pivot aggregation (multi-level rows and
 * columns, subtotals, grand totals, calculated fields, conditional formatting) and returns the matrix with the chart
 * series. A definition without a pivot layout is shown as a pivot of its dimensions on rows.
 */
final readonly class ExecuteAdHocPivotHandler
{
    public function __construct(private DesignedReportPlan $plans, private PivotService $pivots) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ExecuteAdHocPivotQuery $query): array
    {
        $plan = $this->plans->build($query->definition, $query->user, 'spec.');
        $pivot = $plan['pivot'] ?? PivotDefinition::parse(
            ['rows' => array_map(static fn (array $dimension): string => $dimension['granularity'] === null ? $dimension['key'] : "{$dimension['key']}:{$dimension['granularity']}", $plan['spec']->dimensions), 'columns' => [], 'subtotals' => true, 'grand_totals' => true],
            (array) ($query->definition['conditional_formatting'] ?? []),
            (array) ($query->definition['chart_config'] ?? []),
            [...$plan['spec']->measures, ...array_column($plan['calculated'], 'key')],
        );

        return $this->pivots->execute($plan['spec'], $pivot, $plan['calculated'], $query->user);
    }
}
