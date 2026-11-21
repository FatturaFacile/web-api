<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\ValueObjects;

/**
 * Per-cost-center real-time budget availability breakdown for a Purchase Requisition.
 */
final readonly class CostCenterBudgetLineCheck
{
    public function __construct(
        public string $costCenterId,
        public ?string $costCenterCode,
        public ?string $costCenterName,
        public string $budgetCurrency,
        public string $prCurrency,
        public string $exchangeRateApplied,
        public int $fiscalYear,
        public bool $hasConfiguredBudget,
        public string $allocatedBudget,
        public string $actualSpent,
        public string $committedAmount,
        public string $residualAvailable,
        public string $requestedAmountOriginal,
        public string $requestedAmountInBudgetCurrency,
        public string $projectedResidual,
        public bool $isOverBudget,
        public string $overageAmount,
        public string $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cost_center_id' => $this->costCenterId,
            'cost_center_code' => $this->costCenterCode,
            'cost_center_name' => $this->costCenterName,
            'budget_currency' => $this->budgetCurrency,
            'pr_currency' => $this->prCurrency,
            'exchange_rate_applied' => $this->exchangeRateApplied,
            'fiscal_year' => $this->fiscalYear,
            'has_configured_budget' => $this->hasConfiguredBudget,
            'allocated_budget' => $this->allocatedBudget,
            'actual_spent' => $this->actualSpent,
            'committed_amount' => $this->committedAmount,
            'residual_available' => $this->residualAvailable,
            'requested_amount_original' => $this->requestedAmountOriginal,
            'requested_amount' => $this->requestedAmountInBudgetCurrency,
            'projected_residual' => $this->projectedResidual,
            'is_over_budget' => $this->isOverBudget,
            'overage_amount' => $this->overageAmount,
            'source' => $this->source,
        ];
    }
}
