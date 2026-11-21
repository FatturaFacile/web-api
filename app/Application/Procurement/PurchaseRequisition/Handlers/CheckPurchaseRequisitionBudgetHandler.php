<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Queries\CheckPurchaseRequisitionBudgetQuery;
use App\Domain\Procurement\PurchaseRequisition\Contracts\BudgetAvailabilityGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;

/**
 * Read-only query handler computing live residual budget availability without mutating persistence state.
 */
final readonly class CheckPurchaseRequisitionBudgetHandler
{
    public function __construct(
        private RequisitionTotalsDomainService $totalsService,
        private BudgetAvailabilityGatewayInterface $budgetGateway,
    ) {}

    public function handle(CheckPurchaseRequisitionBudgetQuery $query): BudgetCheckResult
    {
        $summary = $this->totalsService->summarizeDraftPayload($query->costCenterId, $query->lines);
        $amountsByCostCenter = $summary['amounts_by_cost_center'];

        if (array_key_exists('__UNASSIGNED__', $amountsByCostCenter)) {
            throw new ProcurementRuleViolation(
                __('purchase_requisition.errors.cost_center_required'),
                'cost_center_id'
            );
        }

        return $this->budgetGateway->checkAvailability(
            tenantId: $query->tenantId,
            amountsByCostCenter: $amountsByCostCenter,
            currency: $query->currency,
            fiscalYear: $query->fiscalYear,
            excludeRequisitionId: $query->excludeRequisitionId,
        );
    }
}
