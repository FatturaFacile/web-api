<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class ProjectBudgetDTO
{
    /**
     * @param  array<int, array{cost_category: string, description: string, planned_quantity: float, planned_unit_cost: float, planned_total_cost: float, planned_revenue_amount?: float, phase_id?: ?string}>  $lines
     */
    public function __construct(
        public string $projectId,
        public float $totalRevenueBudget,
        public float $totalCostBudget,
        public float $labourBudget = 0.00,
        public float $materialBudget = 0.00,
        public float $supplierBudget = 0.00,
        public float $fleetBudget = 0.00,
        public float $expenseBudget = 0.00,
        public float $contingencyBudget = 0.00,
        public array $lines = [],
        public ?string $revisionReason = null,
        public ?string $userId = null
    ) {}
}
