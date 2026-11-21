<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Queries\GetPendingApprovalsQuery;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieves Purchase Requisitions awaiting approval from the current authenticated approver.
 */
final readonly class GetPendingApprovalsHandler
{
    public function __construct(
        private ApprovalWorkflowGatewayInterface $approvalGateway,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PurchaseRequisition>
     */
    public function handle(GetPendingApprovalsQuery $query): LengthAwarePaginator
    {
        $pendingIds = $this->approvalGateway->getPendingRequisitionIdsForUser($query->tenantId, $query->user);

        $perPage = max(1, min(100, $query->perPage));
        $page = max(1, $query->page);

        if ($pendingIds === []) {
            return PurchaseRequisition::query()
                ->whereRaw('1 = 0')
                ->paginate(perPage: $perPage, page: $page);
        }

        return PurchaseRequisition::query()
            ->where('tenant_id', $query->tenantId)
            ->where('status', RequisitionStatus::PendingApproval->value)
            ->whereIn('id', $pendingIds)
            ->with(['lines', 'requester', 'project', 'approvalLogs.approver'])
            ->orderByDesc('submitted_at')
            ->paginate(perPage: $perPage, page: $page);
    }
}
