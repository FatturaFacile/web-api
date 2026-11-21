<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Contracts;

use App\Domain\Procurement\PurchaseRequisition\Enums\BudgetEnforcementPolicy;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;

/**
 * Real-time budget verification gateway connecting Mod 044 with Mod 019 / Mod 053 / Mod 064 / Mod 022 budgets.
 */
interface BudgetAvailabilityGatewayInterface
{
    /**
     * Checks residual budget per cost center in real time without mutating persistence state.
     *
     * @param  array<string, string>  $amountsByCostCenter  Map of cost_center_id => requested decimal amount in PR currency
     */
    public function checkAvailability(
        string $tenantId,
        array $amountsByCostCenter,
        string $currency,
        int $fiscalYear,
        ?string $excludeRequisitionId = null,
    ): BudgetCheckResult;

    /**
     * Resolves the tenant's active budget enforcement policy (warning vs. hard_block).
     */
    public function resolveEnforcementPolicy(string $tenantId): BudgetEnforcementPolicy;
}
