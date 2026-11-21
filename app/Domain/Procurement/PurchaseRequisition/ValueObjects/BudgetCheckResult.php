<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\ValueObjects;

use App\Domain\Procurement\PurchaseRequisition\Enums\BudgetEnforcementPolicy;

/**
 * Aggregated real-time budget availability result across all cost centers touched by a Purchase Requisition.
 */
final readonly class BudgetCheckResult
{
    /**
     * @param  list<CostCenterBudgetLineCheck>  $costCenters
     */
    public function __construct(
        public string $currency,
        public int $fiscalYear,
        public BudgetEnforcementPolicy $enforcementPolicy,
        public string $allocatedBudget,
        public string $actualSpent,
        public string $committedAmount,
        public string $residualAvailable,
        public string $requestedAmount,
        public string $projectedResidual,
        public bool $isOverBudget,
        public string $overageAmount,
        public bool $hasConfiguredBudget,
        public string $checkedAt,
        public array $costCenters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotArray(): array
    {
        return [
            'currency' => $this->currency,
            'fiscal_year' => $this->fiscalYear,
            'enforcement_policy' => $this->enforcementPolicy->value,
            'has_configured_budget' => $this->hasConfiguredBudget,
            'allocated_budget' => $this->allocatedBudget,
            'actual_spent' => $this->actualSpent,
            'committed_amount' => $this->committedAmount,
            'residual_available' => $this->residualAvailable,
            'requested_amount' => $this->requestedAmount,
            'projected_residual' => $this->projectedResidual,
            'is_over_budget' => $this->isOverBudget,
            'overage_amount' => $this->overageAmount,
            'checked_at' => $this->checkedAt,
            'cost_centers' => array_map(
                static fn (CostCenterBudgetLineCheck $check): array => $check->toArray(),
                $this->costCenters
            ),
        ];
    }
}
