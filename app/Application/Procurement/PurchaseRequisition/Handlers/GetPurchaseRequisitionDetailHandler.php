<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Queries\GetPurchaseRequisitionDetailQuery;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;

/**
 * Retrieves full Purchase Requisition details including lines, approval steps, immutable audit logs, and bidirectional links.
 */
final readonly class GetPurchaseRequisitionDetailHandler
{
    public function __construct(
        private ApprovalWorkflowGatewayInterface $approvalGateway,
    ) {}

    /**
     * @return array{requisition: PurchaseRequisition, approval_steps: list<array<string, mixed>>}
     */
    public function handle(GetPurchaseRequisitionDetailQuery $query): array
    {
        $requisition = PurchaseRequisition::query()
            ->where('tenant_id', $query->tenantId)
            ->whereKey($query->requisitionId)
            ->with([
                'lines.project',
                'requester',
                'project',
                'approvalLogs.approver',
                'documentLinks',
            ])
            ->firstOrFail();

        return [
            'requisition' => $requisition,
            'approval_steps' => $this->approvalGateway->getApprovalSteps($requisition),
        ];
    }
}
