<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * The budget of a project by cost category. The total cost budget is the sum of the categories (contingency included).
 */
final readonly class ProjectBudgetDTO
{
    /**
     * @param  array<int, array{cost_category: string, description: string, planned_quantity: string|int|float, planned_unit_cost: string|int|float, planned_total_cost?: string|int|float|null, planned_revenue_amount?: string|int|float|null, phase_id?: ?string}>  $lines
     */
    public function __construct(
        public string $projectId,
        public string $totalRevenueBudget,
        public string $labourBudget = '0',
        public string $materialBudget = '0',
        public string $supplierBudget = '0',
        public string $fleetBudget = '0',
        public string $expenseBudget = '0',
        public string $contingencyBudget = '0',
        public array $lines = [],
        public ?string $revisionReason = null,
        public ?string $userId = null
    ) {}
}
