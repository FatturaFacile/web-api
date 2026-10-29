<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

interface ProductionCostingServiceInterface
{
    /**
     * Calculates the standard cost rollup for a production order based on BOM and Routing.
     *
     * @return array{
     *     material_cost: float,
     *     labor_cost: float,
     *     machine_cost: float,
     *     overhead_cost: float,
     *     total_standard_cost: float
     * }
     */
    public function calculateStandardCostRollup(string $productionOrderId): array;

    /**
     * Accumulates actual costs and calculates variances against standard costs.
     *
     * @return array{
     *     actual_material_cost: float,
     *     actual_labor_cost: float,
     *     actual_machine_cost: float,
     *     actual_overhead_cost: float,
     *     actual_total_cost: float,
     *     variance_total: float,
     *     variance_type: string
     * }
     */
    public function calculateActualCostAndVariance(string $productionOrderId): array;

    public function postProductionWipAccounting(string $productionOrderId): void;

    public function postProductionCompletionAccounting(string $productionOrderId): void;
}
